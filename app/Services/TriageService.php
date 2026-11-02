<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Providers\TriageProviderInterface;
use App\Providers\RuleBasedProvider;
use App\Helpers\Logger;

/**
 * TriageService
 *
 * Orchestrates the complete triage pipeline:
 *   1. Detect prompt injection
 *   2. Evaluate refund policy (deterministic, before AI)
 *   3. Call the triage provider (rule-based or LLM)
 *   4. Persist results
 *   5. Create refund request if recommended
 *   6. Write audit events
 *
 * Idempotency: Re-triaging a ticket appends a new triage_results row.
 * The latest result is used for display. No existing decisions are silently overwritten.
 */
class TriageService
{
    private TriageProviderInterface $provider;
    private RefundPolicyService     $refundPolicy;
    private PromptInjectionDetector $injectionDetector;

    public function __construct()
    {
        $this->refundPolicy      = new RefundPolicyService();
        $this->injectionDetector = new PromptInjectionDetector();
        $this->provider          = $this->resolveProvider();
    }

    private function resolveProvider(): TriageProviderInterface
    {
        $providerName = env('AI_PROVIDER', 'rule_based');

        // Only rule_based is implemented without an API key.
        // LLM providers could be plugged in here by checking for API keys.
        return new RuleBasedProvider();
    }

    /**
     * Run full triage for a ticket.
     *
     * @param string $ticketId   Ticket ID (e.g. "T-1001")
     * @param int|null $actorId  Authenticated user ID (for audit log)
     */
    public function triage(string $ticketId, ?int $actorId = null): array
    {
        $db = Database::getInstance();

        // ── Load ticket ──────────────────────────────────────────────────────
        $stmt = $db->prepare('SELECT * FROM tickets WHERE ticket_id = ? LIMIT 1');
        $stmt->execute([$ticketId]);
        $ticket = $stmt->fetch();

        if (!$ticket) {
            return ['success' => false, 'error' => "Ticket $ticketId not found."];
        }

        // ── Load purchases ───────────────────────────────────────────────────
        $stmt = $db->prepare('SELECT * FROM purchases WHERE ticket_id = ? ORDER BY purchased_at ASC');
        $stmt->execute([$ticketId]);
        $purchases = $stmt->fetchAll();

        // ── Injection detection ──────────────────────────────────────────────
        $injection = $this->injectionDetector->analyse($ticket['subject'], $ticket['body']);

        // Build safe body for triage (strip injected instructions)
        $safeBody = $injection['detected']
            ? $this->injectionDetector->extractLegitimateIntent($ticket['body'])
            : $ticket['body'];

        $safeTicket         = $ticket;
        $safeTicket['body'] = $safeBody;

        if ($injection['detected']) {
            Logger::warn('Prompt injection detected', [
                'ticket_id' => $ticketId,
                'patterns'  => count($injection['patterns']),
            ]);
            $this->writeAuditEvent($ticketId, $actorId, 'injection_detected', [
                'pattern_count' => count($injection['patterns']),
            ]);
        }

        // ── Refund policy evaluation (deterministic, pre-AI) ─────────────────
        $refundDecision = $this->refundPolicy->evaluate($safeTicket, $purchases);

        // SAFETY: If injection detected, override refund decision to no-refund + human review
        if ($injection['detected']) {
            $refundDecision = [
                'should_refund'  => false,
                'amount_inr'     => 0.0,
                'reason'         => 'Refund evaluation suspended: prompt injection attempt detected in this ticket. Human review required.',
                'needs_human'    => true,
                'rule_triggered' => 'injection_block',
            ];
        }

        // ── Provider analysis ─────────────────────────────────────────────────
        $startTime    = microtime(true);
        $providerResult = null;
        $degraded       = false;

        try {
            $providerResult = $this->provider->analyse($safeTicket, $purchases, $refundDecision);
        } catch (\Throwable $e) {
            Logger::error('Triage provider failed', ['ticket' => $ticketId, 'error' => $e->getMessage()]);
            // Degrade safely: use rule-based fallback
            $degraded       = true;
            $fallback        = new RuleBasedProvider();
            $providerResult  = $fallback->analyse($safeTicket, $purchases, $refundDecision);
            $providerResult['degraded']       = true;
            $providerResult['model_provider'] = 'rule_based_fallback';
        }

        $elapsedMs = (int)((microtime(true) - $startTime) * 1000);

        // ── Override needs_human if injection detected ────────────────────────
        if ($injection['detected']) {
            $providerResult['needs_human'] = true;
        }

        // ── Persist triage result ─────────────────────────────────────────────
        $stmt = $db->prepare("
            INSERT INTO triage_results
                (ticket_id, category, severity, refund_requested, refund_recommended,
                 refund_amount_inr, refund_reason, reply_draft, needs_human,
                 confidence, degraded, model_provider, injection_detected)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $refundRequested = (int)$this->bodyRequestsRefund($ticket['body']);

        $stmt->execute([
            $ticketId,
            $providerResult['category'],
            $providerResult['severity'],
            $refundRequested,
            (int)$refundDecision['should_refund'],
            $refundDecision['amount_inr'],
            $refundDecision['reason'],
            $providerResult['reply_draft'],
            (int)$providerResult['needs_human'],
            $providerResult['confidence'],
            (int)($providerResult['degraded'] ?? $degraded),
            $providerResult['model_provider'],
            (int)$injection['detected'],
        ]);

        $triageId = (int)$db->lastInsertId();

        // ── Update ticket status ──────────────────────────────────────────────
        $newStatus = $providerResult['needs_human'] ? 'in_review' : 'triaged';
        $db->prepare("UPDATE tickets SET status = ?, updated_at = NOW() WHERE ticket_id = ?")
           ->execute([$newStatus, $ticketId]);

        // ── Create refund request if recommended ──────────────────────────────
        if ($refundDecision['should_refund'] && $refundDecision['amount_inr'] > 0) {
            $this->createRefundRequest($ticketId, $purchases, $refundDecision);
        }

        // ── Audit event ───────────────────────────────────────────────────────
        $this->writeAuditEvent($ticketId, $actorId, 'ticket_triaged', [
            'triage_id'    => $triageId,
            'category'     => $providerResult['category'],
            'severity'     => $providerResult['severity'],
            'elapsed_ms'   => $elapsedMs,
        ]);

        // ── Build response ────────────────────────────────────────────────────
        return [
            'success'   => true,
            'ticket_id' => $ticketId,
            'category'  => $providerResult['category'],
            'severity'  => $providerResult['severity'],
            'refund'    => [
                'should_refund'  => $refundDecision['should_refund'],
                'amount_inr'     => $refundDecision['amount_inr'],
                'reason'         => $refundDecision['reason'],
                'rule_triggered' => $refundDecision['rule_triggered'] ?? null,
            ],
            'reply_draft'       => $providerResult['reply_draft'],
            'needs_human'       => $providerResult['needs_human'],
            'confidence'        => $providerResult['confidence'],
            'degraded'          => (bool)($providerResult['degraded'] ?? $degraded),
            'injection_detected'=> $injection['detected'],
            'model_provider'    => $providerResult['model_provider'],
        ];
    }

    private function bodyRequestsRefund(string $body): bool
    {
        $lower = mb_strtolower($body);
        return str_contains($lower, 'refund') || str_contains($lower, 'money back');
    }

    private function createRefundRequest(string $ticketId, array $purchases, array $refundDecision): void
    {
        $db = Database::getInstance();

        // Identify the purchase to refund (prefer most recent successful non-trial)
        $eligible = array_filter($purchases, fn($p) =>
            $p['status'] === 'success' && $p['purchase_type'] !== 'trial');

        if (empty($eligible)) {
            $eligible = array_filter($purchases, fn($p) => $p['status'] === 'success');
        }

        if (empty($eligible)) {
            return;
        }

        // Use the most recent eligible purchase
        usort($eligible, fn($a, $b) => $b['purchased_at'] <=> $a['purchased_at']);
        $purchase = array_values($eligible)[0];

        // Idempotency: don't create duplicate pending refund for same ticket+purchase
        $stmt = $db->prepare(
            "SELECT id FROM refund_requests WHERE ticket_id = ? AND purchase_id = ? AND status = 'pending' LIMIT 1"
        );
        $stmt->execute([$ticketId, $purchase['purchase_id']]);
        if ($stmt->fetch()) {
            return; // Already exists
        }

        $stmt = $db->prepare("
            INSERT INTO refund_requests
                (ticket_id, purchase_id, requested_amount_inr, status, reason)
            VALUES (?, ?, ?, 'pending', ?)
        ");
        $stmt->execute([
            $ticketId,
            $purchase['purchase_id'],
            $refundDecision['amount_inr'],
            $refundDecision['reason'],
        ]);
    }

    private function writeAuditEvent(string $ticketId, ?int $actorId, string $eventType, array $data): void
    {
        try {
            $db   = Database::getInstance();
            $stmt = $db->prepare(
                "INSERT INTO audit_events (ticket_id, actor_id, event_type, event_data) VALUES (?, ?, ?, ?)"
            );
            $stmt->execute([$ticketId, $actorId, $eventType, json_encode($data)]);
        } catch (\Throwable $e) {
            Logger::error('Audit write failed', ['error' => $e->getMessage()]);
        }
    }
}


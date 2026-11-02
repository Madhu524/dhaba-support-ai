<?php
declare(strict_types=1);

namespace App\Services;

/**
 * RefundPolicyService
 *
 * CRITICAL: This service makes refund recommendations using deterministic rules.
 * The AI model NEVER authorises refunds directly.
 * All financial decisions must pass through this service.
 *
 * Policy Assumptions (documented):
 * 1. A duplicate successful charge on the same day for the same product type
 *    → recommend full refund of the smaller/duplicate amount.
 * 2. Cancellation within 3 days of a successful subscription purchase
 *    → recommend full refund if usage < 5 app opens.
 * 3. Payment status "initiated" (failed) but money deducted
 *    → flag for human review (requires bank coordination; no auto-refund).
 * 4. Auto-renewal dispute (claim setting was off)
 *    → recommend human review; do not auto-approve (cannot verify setting).
 * 5. Trial (₹1) purchases → never refundable (₹1 is negligible; policy assumption).
 * 6. Partial refund for unused period → not auto-approved; needs human review.
 * 7. A purchase with status "refunded" → no further refund possible (idempotency).
 * 8. A purchase with status "failed"   → no refund (no successful charge confirmed).
 * 9. Maximum refund amount never exceeds the verified successful purchase total.
 *10. Inactivity alone (low app opens) is NOT sufficient for automatic refund.
 */
class RefundPolicyService
{
    /**
     * Evaluate whether a refund should be recommended.
     *
     * @param array $ticket    Normalised ticket data
     * @param array $purchases Array of purchase records from DB
     * @return array{
     *   should_refund: bool,
     *   amount_inr: float,
     *   reason: string,
     *   needs_human: bool,
     *   rule_triggered: string
     * }
     */
    public function evaluate(array $ticket, array $purchases): array
    {
        $noRefund = fn(string $reason, string $rule = 'no_match') => [
            'should_refund'  => false,
            'amount_inr'     => 0.0,
            'reason'         => $reason,
            'needs_human'    => false,
            'rule_triggered' => $rule,
        ];

        $humanReview = fn(string $reason, string $rule) => [
            'should_refund'  => false,
            'amount_inr'     => 0.0,
            'reason'         => $reason,
            'needs_human'    => true,
            'rule_triggered' => $rule,
        ];

        if (empty($purchases)) {
            return $noRefund('No verified purchase records found. Cannot recommend refund.');
        }

        // ── Rule 1: Already refunded ──────────────────────────────────────────
        $refunded = array_filter($purchases, fn($p) => $p['status'] === 'refunded');
        if (!empty($refunded)) {
            return $noRefund('A refund has already been processed for this ticket. No further refund is available.', 'already_refunded');
        }

        $successfulPurchases = array_filter($purchases, fn($p) => $p['status'] === 'success');
        $successTotal        = array_sum(array_column(iterator_to_array((function($a){ foreach($a as $v) yield $v; })($successfulPurchases), false), 'amount_inr'));

        // ── Rule 2: Duplicate charge (same day, same type, both success) ──────
        $duplicateResult = $this->checkDuplicateCharge($successfulPurchases);
        if ($duplicateResult !== null) {
            return [
                'should_refund'  => true,
                'amount_inr'     => $duplicateResult['amount'],
                'reason'         => 'Duplicate successful charge detected on the same day for the same purchase type. Recommend refund of duplicate amount.',
                'needs_human'    => false,
                'rule_triggered' => 'duplicate_charge',
            ];
        }

        // ── Rule 3: Inititated (failed) payment with money deducted ───────────
        $initiated = array_filter($purchases, fn($p) => $p['status'] === 'initiated');
        if (!empty($initiated)) {
            return $humanReview(
                'Payment status is "initiated" (payment failed at gateway). The charge may be a hold or pending reversal. Human review and bank coordination required.',
                'initiated_payment'
            );
        }

        // ── Rule 4: Failed purchases – no refund ──────────────────────────────
        $failed = array_filter($purchases, fn($p) => $p['status'] === 'failed');
        if (!empty($failed) && empty($successfulPurchases)) {
            return $noRefund('No confirmed successful payment. No refund can be issued for failed transactions.', 'no_successful_payment');
        }

        if (empty($successfulPurchases)) {
            return $noRefund('No successful purchase records found.', 'no_successful_payment');
        }

        // ── Rule 5: Trial (₹1) – not refundable ──────────────────────────────
        $trialOnly = array_filter($successfulPurchases, fn($p) => $p['purchase_type'] === 'trial');
        $subOnly   = array_filter($successfulPurchases, fn($p) => $p['purchase_type'] !== 'trial');
        if (empty($subOnly) && !empty($trialOnly)) {
            return $noRefund('The ₹1 trial fee is not eligible for a refund under our policy.', 'trial_not_refundable');
        }

        // ── Rule 6: Recent purchase (within 3 days) + low usage ──────────────
        $appOpens     = (int)($ticket['app_opens_since_renewal'] ?? 999);
        $refDateStr   = $ticket['received_at'] ?? null;
        $recentResult = $this->checkRecentCancellation($subOnly, $appOpens, $refDateStr);
        if ($recentResult !== null) {
            return [
                'should_refund'  => true,
                'amount_inr'     => $recentResult['amount'],
                'reason'         => 'Subscription purchased within 3 days of inquiry with very low usage (fewer than 5 app opens). Recommend full refund.',
                'needs_human'    => false,
                'rule_triggered' => 'recent_cancellation',
            ];
        }

        // ── Rule 7: Auto-renewal dispute ──────────────────────────────────────
        $bodyLower = mb_strtolower($ticket['body'] ?? '');
        if (str_contains($bodyLower, 'auto-renew') || str_contains($bodyLower, 'auto renew') ||
            str_contains($bodyLower, 'auto renewal') || str_contains($bodyLower, 'autorenewal') ||
            str_contains($bodyLower, 'without my consent') || str_contains($bodyLower, 'without consent')) {
            return $humanReview(
                'Customer disputes an auto-renewal charge and claims to have disabled auto-renewal. Cannot verify the setting state from ticket alone. Human review required.',
                'auto_renewal_dispute'
            );
        }

        // ── Rule 8: Trial confusion – subscription charged unexpectedly ───────
        if (!empty($trialOnly) && !empty($subOnly)) {
            $subPurchase = array_values($subOnly)[count($subOnly) - 1]; // most recent
            $subDate     = new \DateTime($subPurchase['purchased_at']);
            $daysSinceSub = (new \DateTime())->diff($subDate)->days;

            if ($daysSinceSub <= 7 && $appOpens <= 10) {
                return [
                    'should_refund'  => true,
                    'amount_inr'     => (float)$subPurchase['amount_inr'],
                    'reason'         => 'Customer was auto-charged for annual subscription after trial without clear consent indication. Recent charge with minimal usage.',
                    'needs_human'    => false,
                    'rule_triggered' => 'trial_to_subscription_confusion',
                ];
            }

            return $humanReview(
                'Customer reports unexpected subscription charge after trial. Charge occurred more than 7 days ago or usage is too high for automatic approval. Human review required.',
                'trial_to_subscription_review'
            );
        }

        // ── Rule 9: Partial refund for low usage (6+ months) ─────────────────
        if ($appOpens < 10) {
            $oldestPurchase = null;
            foreach ($subOnly as $p) {
                if ($oldestPurchase === null || $p['purchased_at'] < $oldestPurchase['purchased_at']) {
                    $oldestPurchase = $p;
                }
            }
            if ($oldestPurchase) {
                $purchaseDate    = new \DateTime($oldestPurchase['purchased_at']);
                $monthsSincePurchase = (new \DateTime())->diff($purchaseDate)->m
                    + ((new \DateTime())->diff($purchaseDate)->y * 12);

                if ($monthsSincePurchase >= 3) {
                    return $humanReview(
                        "Subscription was purchased {$monthsSincePurchase} months ago with minimal usage ({$appOpens} app opens). Policy does not cover automatic partial refunds. Human review required to assess compassionate exception.",
                        'low_usage_partial_refund'
                    );
                }
            }
        }

        // ── Default: no automatic refund ─────────────────────────────────────
        return $noRefund(
            'No automatic refund policy condition met. If the customer has a valid concern, it may be addressed by a human agent.',
            'no_policy_match'
        );
    }

    /** Detect duplicate charges: same purchase_type, same day, both success. */
    private function checkDuplicateCharge(array $successPurchases): ?array
    {
        $byDay = [];
        foreach ($successPurchases as $p) {
            if ((float)$p['amount_inr'] < 2.0) {
                continue; // ignore ₹1 trial
            }
            $day  = substr($p['purchased_at'], 0, 10);
            $type = $p['purchase_type'];
            $key  = $day . '|' . $type;
            $byDay[$key][] = $p;
        }

        foreach ($byDay as $group) {
            if (count($group) >= 2) {
                // Refund the smaller/later duplicate
                usort($group, fn($a, $b) => $a['purchased_at'] <=> $b['purchased_at']);
                $duplicate = $group[count($group) - 1];
                return ['amount' => (float)$duplicate['amount_inr']];
            }
        }

        return null;
    }

    /** Detect subscription within 3 days with < 5 opens. */
    private function checkRecentCancellation(array $subPurchases, int $appOpens, ?string $referenceDateStr = null): ?array
    {
        if ($appOpens >= 5) {
            return null;
        }

        $refDate = $referenceDateStr ? new \DateTime($referenceDateStr) : new \DateTime();

        foreach ($subPurchases as $p) {
            $purchaseDate = new \DateTime($p['purchased_at']);
            // Absolute day difference between reference date (ticket received or now) and purchase date
            $daysSince = (int)$refDate->diff($purchaseDate)->days;

            if ($daysSince <= 3) {
                return ['amount' => (float)$p['amount_inr']];
            }
        }

        return null;
    }
}


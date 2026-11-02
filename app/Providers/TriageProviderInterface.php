<?php
declare(strict_types=1);

namespace App\Providers;

/**
 * Interface for AI triage providers.
 * Implementations: RuleBasedProvider, OptionalLLMProvider.
 */
interface TriageProviderInterface
{
    /**
     * Analyse a ticket and return a raw triage result.
     *
     * @param array $ticketData  Normalised ticket fields (ticket_id, subject, body, etc.)
     * @param array $purchases   Verified purchase records from the database
     * @param array $refundDecision Pre-computed refund recommendation from RefundPolicyService
     * @return array{
     *   category: string,
     *   severity: string,
     *   reply_draft: string,
     *   needs_human: bool,
     *   confidence: float,
     *   degraded: bool,
     *   model_provider: string
     * }
     */
    public function analyse(array $ticketData, array $purchases, array $refundDecision): array;
}


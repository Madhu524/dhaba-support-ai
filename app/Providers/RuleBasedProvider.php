<?php
declare(strict_types=1);

namespace App\Providers;

/**
 * RuleBasedProvider
 *
 * Fully deterministic triage engine. No external API calls.
 * This provider is always available as a safe fallback.
 */
class RuleBasedProvider implements TriageProviderInterface
{
    private const CATEGORIES = [
        'billing', 'cancellation', 'technical', 'account',
        'feature_request', 'complaint', 'general',
    ];

    private const SEVERITIES = ['low', 'medium', 'high', 'critical'];

    // Keyword maps for category detection
    private const BILLING_KEYWORDS = [
        'charged', 'charge', 'deducted', 'payment', 'invoice', 'amount',
        'money', 'refund', 'billing', 'transaction', 'bank', 'credit card',
        'twice', 'duplicate', 'overcharged', 'subscription fee',
    ];

    private const CANCELLATION_KEYWORDS = [
        'cancel', 'cancellation', 'unsubscribe', 'stop subscription',
        'terminate', 'end subscription', 'discontinue',
    ];

    private const TECHNICAL_KEYWORDS = [
        'crash', 'crashes', 'bug', 'error', 'issue', 'problem', 'not working',
        'broken', 'fix', 'update', 'version', 'reinstall', 'install',
        'freeze', 'hang', 'slow', 'performance', 'glitch', 'missing data',
        'disappeared', 'data loss', 'data missing',
    ];

    private const ACCOUNT_KEYWORDS = [
        'account', 'login', 'password', 'locked', 'access', 'sign in',
        'sign up', 'forgot password', 'reset password', 'cannot login',
        'account locked', 'blocked', '2fa', 'two factor',
    ];

    private const FEATURE_KEYWORDS = [
        'feature', 'request', 'suggest', 'idea', 'add', 'would be great',
        'dark mode', 'enhancement', 'improvement', 'wish', 'love if',
    ];

    private const COMPLAINT_KEYWORDS = [
        'complaint', 'disappointed', 'frustrated', 'unacceptable',
        'terrible', 'awful', 'worst', 'horrible', 'disgusted',
        'angry', 'furious', 'escalate', 'manager', 'legal',
    ];

    private const HIGH_SEVERITY_SIGNALS = [
        'urgent', 'immediately', 'asap', 'critical', 'emergency',
        'legal action', 'lawyer', 'court', 'chargeback', 'fraud',
        'scam', 'stolen', 'threatening', 'blackmail',
    ];

    public function analyse(array $ticketData, array $purchases, array $refundDecision): array
    {
        $subject  = mb_strtolower($ticketData['subject'] ?? '');
        $body     = mb_strtolower($ticketData['body'] ?? '');
        $combined = $subject . ' ' . $body;

        $category = $this->classifyCategory($combined);
        $severity = $this->classifySeverity($combined, $category, $refundDecision, $ticketData);
        $needsHuman = $this->determineHumanReview($category, $severity, $refundDecision, $ticketData);
        $confidence = $this->computeConfidence($combined, $category);
        $replyDraft = $this->generateReply($ticketData, $category, $refundDecision);

        return [
            'category'       => $category,
            'severity'       => $severity,
            'reply_draft'    => $replyDraft,
            'needs_human'    => $needsHuman,
            'confidence'     => round($confidence, 3),
            'degraded'       => false,
            'model_provider' => 'rule_based',
        ];
    }

    private function classifyCategory(string $combined): string
    {
        $scores = [
            'cancellation'   => $this->score($combined, self::CANCELLATION_KEYWORDS),
            'billing'        => $this->score($combined, self::BILLING_KEYWORDS),
            'technical'      => $this->score($combined, self::TECHNICAL_KEYWORDS),
            'account'        => $this->score($combined, self::ACCOUNT_KEYWORDS),
            'feature_request'=> $this->score($combined, self::FEATURE_KEYWORDS),
            'complaint'      => $this->score($combined, self::COMPLAINT_KEYWORDS),
        ];

        // Cancel + refund → cancellation wins over billing
        if ($scores['cancellation'] >= 1 && $scores['billing'] >= 1) {
            $scores['cancellation'] += 2;
        }

        arsort($scores);
        $best = array_key_first($scores);

        return ($scores[$best] > 0) ? $best : 'general';
    }

    private function classifySeverity(string $combined, string $category, array $refund, array $ticket): string
    {
        // Critical: fraud, legal threats
        foreach (['fraud', 'scam', 'legal action', 'lawyer', 'chargeback', 'court'] as $kw) {
            if (str_contains($combined, $kw)) {
                return 'critical';
            }
        }

        // High: urgent signals
        foreach (self::HIGH_SEVERITY_SIGNALS as $kw) {
            if (str_contains($combined, $kw)) {
                return 'high';
            }
        }

        // High: initiated payment (money deducted, no subscription)
        if (in_array($category, ['billing', 'cancellation'], true)) {
            if ($refund['rule_triggered'] === 'initiated_payment') {
                return 'high';
            }
        }

        // Medium: billing disputes, cancellations, account locked
        if (in_array($category, ['billing', 'cancellation', 'account'], true)) {
            return 'medium';
        }

        // Medium: technical crash
        if ($category === 'technical') {
            $appOpens = (int)($ticket['app_opens_since_renewal'] ?? 0);
            return ($appOpens === 0) ? 'high' : 'medium';
        }

        // Low: feature requests, general
        return 'low';
    }

    private function determineHumanReview(string $category, string $severity, array $refund, array $ticket): bool
    {
        if ($refund['needs_human']) {
            return true;
        }
        if (in_array($severity, ['high', 'critical'], true)) {
            return true;
        }
        if ($category === 'cancellation') {
            return true;
        }
        // Data loss always needs human
        $combined = mb_strtolower(($ticket['subject'] ?? '') . ' ' . ($ticket['body'] ?? ''));
        if (str_contains($combined, 'data') && (str_contains($combined, 'missing') || str_contains($combined, 'gone') || str_contains($combined, 'disappeared'))) {
            return true;
        }
        return false;
    }

    private function computeConfidence(string $combined, string $category): float
    {
        $maps = [
            'billing'         => self::BILLING_KEYWORDS,
            'cancellation'    => self::CANCELLATION_KEYWORDS,
            'technical'       => self::TECHNICAL_KEYWORDS,
            'account'         => self::ACCOUNT_KEYWORDS,
            'feature_request' => self::FEATURE_KEYWORDS,
            'complaint'       => self::COMPLAINT_KEYWORDS,
            'general'         => [],
        ];

        if ($category === 'general') {
            return 0.55;
        }

        $keywords  = $maps[$category] ?? [];
        $matches   = $this->score($combined, $keywords);
        $base      = 0.60;
        $boost     = min(0.38, $matches * 0.06);

        return $base + $boost;
    }

    private function generateReply(array $ticket, string $category, array $refund): string
    {
        $ticketId   = $ticket['ticket_id'] ?? 'your ticket';
        $shouldRefund = $refund['should_refund'];
        $refundAmt    = $refund['amount_inr'];
        $needsHuman   = $refund['needs_human'];

        $greeting  = "Dear Customer,\n\nThank you for reaching out to Dhaba Support. We have received your request (Ticket #{$ticketId}) and appreciate you bringing this to our attention.\n\n";
        $signature = "\n\nWarm regards,\nDhaba Support Team\n\n*This reply has been drafted by our support assistant and reviewed for accuracy.*";

        switch ($category) {
            case 'billing':
                if ($shouldRefund) {
                    return $greeting .
                        "We have reviewed your billing concern and our records confirm that a refund of ₹" . number_format($refundAmt, 2) . " is recommended for your account.\n\n" .
                        "A refund request has been created and will be reviewed by our billing team within 2–3 business days. Once approved, the amount will be credited to your original payment method within 5–7 business days.\n\n" .
                        "We sincerely apologise for the inconvenience caused." . $signature;
                }
                if ($needsHuman) {
                    return $greeting .
                        "We have reviewed your billing concern and escalated it to our billing team for detailed investigation.\n\n" .
                        "Our billing specialist will contact you within 1 business day with a resolution. Please do not raise a chargeback during this period as it may delay the process." . $signature;
                }
                return $greeting .
                    "We have received your billing inquiry. Our team will review your account and get back to you within 2 business days." . $signature;

            case 'cancellation':
                if ($shouldRefund) {
                    return $greeting .
                        "We have processed your cancellation request. Based on our review, a refund of ₹" . number_format($refundAmt, 2) . " has been recommended and submitted for approval.\n\n" .
                        "Your subscription will be cancelled effective immediately. If approved, the refund will reach your original payment method within 5–7 business days." . $signature;
                }
                if ($needsHuman) {
                    return $greeting .
                        "We have received your cancellation request and escalated it to our team for review.\n\n" .
                        "A specialist will contact you within 1 business day to assist with your cancellation and evaluate your eligibility for a refund based on our policy." . $signature;
                }
                return $greeting .
                    "We have received your cancellation request. Our team will process this within 1–2 business days." . $signature;

            case 'technical':
                return $greeting .
                    "We apologise for the technical difficulty you are experiencing. Our engineering team is aware of issues affecting some users after recent updates.\n\n" .
                    "Please try the following steps:\n" .
                    "1. Ensure you have the latest app version installed.\n" .
                    "2. Clear the app cache and data from your device settings.\n" .
                    "3. Restart your device and relaunch the app.\n\n" .
                    "If the issue persists, our technical team will investigate and provide a fix. We will keep you updated on the resolution." . $signature;

            case 'account':
                return $greeting .
                    "We are sorry to hear that you are having trouble accessing your account.\n\n" .
                    "To resolve this, please use the 'Forgot Password' option on the login page to reset your credentials. If your account is locked, it will typically unlock automatically after 30 minutes.\n\n" .
                    "If you continue to experience issues, our account support team will assist you directly." . $signature;

            case 'feature_request':
                return $greeting .
                    "Thank you for your thoughtful feedback! We love hearing from our users.\n\n" .
                    "Your feature suggestion has been logged and forwarded to our product team for consideration in our upcoming roadmap. We appreciate your enthusiasm and support.\n\n" .
                    "Stay tuned for updates — we are always working to improve the experience for users like you!" . $signature;

            case 'complaint':
                return $greeting .
                    "We sincerely apologise for the experience that has led to your complaint. This is not the standard of service we aspire to.\n\n" .
                    "Your concern has been escalated to a senior member of our team who will personally review your case and contact you within 24 hours with a resolution." . $signature;

            default:
                return $greeting .
                    "We have received your message and our team will review it shortly. A support agent will follow up with you within 2 business days." . $signature;
        }
    }

    private function score(string $text, array $keywords): int
    {
        $count = 0;
        foreach ($keywords as $kw) {
            if (str_contains($text, $kw)) {
                $count++;
            }
        }
        return $count;
    }
}


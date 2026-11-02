<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Middleware\AuthMiddleware;

class DashboardController
{
    private AuthMiddleware $auth;

    public function __construct()
    {
        $this->auth = new AuthMiddleware();
    }

    /** GET /api/dashboard/summary */
    public function summary(): void
    {
        $this->auth->requireAuth();
        $db = Database::getInstance();

        $total     = (int)$db->query("SELECT COUNT(*) FROM tickets")->fetchColumn();
        $pending   = (int)$db->query("SELECT COUNT(*) FROM tickets WHERE status = 'new'")->fetchColumn();
        $inReview  = (int)$db->query("SELECT COUNT(*) FROM tickets WHERE status = 'in_review'")->fetchColumn();
        $resolved  = (int)$db->query("SELECT COUNT(*) FROM tickets WHERE status IN ('resolved','closed')")->fetchColumn();
        $highSev   = (int)$db->query("
            SELECT COUNT(DISTINCT t.ticket_id) FROM triage_results t
            WHERE t.severity IN ('high','critical')
              AND t.id = (SELECT MAX(id) FROM triage_results WHERE ticket_id = t.ticket_id)
        ")->fetchColumn();
        $humanReview = (int)$db->query("
            SELECT COUNT(DISTINCT ticket_id) FROM triage_results
            WHERE needs_human = 1
              AND id IN (SELECT MAX(id) FROM triage_results GROUP BY ticket_id)
        ")->fetchColumn();
        $pendingRefunds = (int)$db->query("SELECT COUNT(*) FROM refund_requests WHERE status = 'pending'")->fetchColumn();
        $injections = (int)$db->query("SELECT COUNT(*) FROM audit_events WHERE event_type = 'injection_detected'")->fetchColumn();

        json_response([
            'success' => true,
            'data'    => [
                'total_tickets'     => $total,
                'pending_tickets'   => $pending,
                'in_review'         => $inReview,
                'resolved_tickets'  => $resolved,
                'high_severity'     => $highSev,
                'human_review'      => $humanReview,
                'pending_refunds'   => $pendingRefunds,
                'injection_attempts'=> $injections,
            ],
        ]);
    }

    /** GET /api/dashboard/tickets-by-category */
    public function byCategory(): void
    {
        $this->auth->requireAuth();
        $db = Database::getInstance();

        $rows = $db->query("
            SELECT category, COUNT(*) AS count
            FROM triage_results
            WHERE id IN (SELECT MAX(id) FROM triage_results GROUP BY ticket_id)
            GROUP BY category
            ORDER BY count DESC
        ")->fetchAll();

        json_response(['success' => true, 'data' => $rows]);
    }

    /** GET /api/dashboard/tickets-by-severity */
    public function bySeverity(): void
    {
        $this->auth->requireAuth();
        $db = Database::getInstance();

        $rows = $db->query("
            SELECT severity, COUNT(*) AS count
            FROM triage_results
            WHERE id IN (SELECT MAX(id) FROM triage_results GROUP BY ticket_id)
            GROUP BY severity
            ORDER BY FIELD(severity,'critical','high','medium','low')
        ")->fetchAll();

        json_response(['success' => true, 'data' => $rows]);
    }

    /** GET /api/dashboard/refund-summary */
    public function refundSummary(): void
    {
        $this->auth->requireAuth();
        $db = Database::getInstance();

        $rows = $db->query("
            SELECT status, COUNT(*) AS count, COALESCE(SUM(requested_amount_inr), 0) AS total_requested_inr
            FROM refund_requests
            GROUP BY status
        ")->fetchAll();

        json_response(['success' => true, 'data' => $rows]);
    }

    /** GET /api/dashboard/recent-tickets */
    public function recentTickets(): void
    {
        $this->auth->requireAuth();
        $db = Database::getInstance();

        $limit = min(50, max(1, (int)($_GET['limit'] ?? 10)));
        $stmt  = $db->prepare("
            SELECT t.ticket_id, t.subject, t.status, t.received_at,
                   tr.category, tr.severity, tr.needs_human
            FROM tickets t
            LEFT JOIN triage_results tr ON tr.id = (
                SELECT MAX(id) FROM triage_results WHERE ticket_id = t.ticket_id
            )
            ORDER BY t.received_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        $tickets = $stmt->fetchAll();

        json_response(['success' => true, 'data' => $tickets]);
    }
}


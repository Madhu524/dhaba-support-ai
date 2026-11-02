<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Middleware\AuthMiddleware;

class ReportController
{
    private AuthMiddleware $auth;

    public function __construct()
    {
        $this->auth = new AuthMiddleware();
    }

    /** GET /api/reports/triage */
    public function triage(): void
    {
        $this->auth->requireAuth();
        $db = Database::getInstance();

        $rows = $db->query("
            SELECT tr.ticket_id, t.subject, tr.category, tr.severity,
                   tr.refund_recommended, tr.refund_amount_inr, tr.needs_human,
                   tr.confidence, tr.injection_detected, tr.model_provider,
                   tr.created_at
            FROM triage_results tr
            JOIN tickets t ON t.ticket_id = tr.ticket_id
            WHERE tr.id IN (SELECT MAX(id) FROM triage_results GROUP BY ticket_id)
            ORDER BY tr.created_at DESC
        ")->fetchAll();

        json_response(['success' => true, 'data' => $rows, 'count' => count($rows)]);
    }

    /** GET /api/reports/refunds */
    public function refunds(): void
    {
        $this->auth->requireAuth();
        $db = Database::getInstance();

        $rows = $db->query("
            SELECT r.*, t.subject AS ticket_subject, u.name AS reviewer_name
            FROM refund_requests r
            JOIN tickets t ON t.ticket_id = r.ticket_id
            LEFT JOIN users u ON u.id = r.reviewed_by
            ORDER BY r.created_at DESC
        ")->fetchAll();

        json_response(['success' => true, 'data' => $rows, 'count' => count($rows)]);
    }

    /** GET /api/reports/export */
    public function export(): void
    {
        $this->auth->requireAuth();
        $db = Database::getInstance();

        $tickets   = $db->query("SELECT * FROM tickets ORDER BY received_at")->fetchAll();
        $triages   = $db->query("SELECT * FROM triage_results ORDER BY created_at")->fetchAll();
        $refunds   = $db->query("SELECT * FROM refund_requests ORDER BY created_at")->fetchAll();

        json_response([
            'success'         => true,
            'exported_at'     => date('Y-m-d\TH:i:s\Z'),
            'tickets'         => $tickets,
            'triage_results'  => $triages,
            'refund_requests' => $refunds,
        ]);
    }
}


<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Middleware\AuthMiddleware;
use App\Helpers\Logger;

class RefundController
{
    private AuthMiddleware $auth;

    public function __construct()
    {
        $this->auth = new AuthMiddleware();
    }

    /** GET /api/refunds */
    public function index(): void
    {
        $this->auth->requireAuth();
        $db = Database::getInstance();

        $status = $_GET['status'] ?? '';
        $where  = '1=1';
        $params = [];

        if (!empty($status)) {
            $where    = 'r.status = ?';
            $params[] = $status;
        }

        $stmt = $db->prepare("
            SELECT r.*, u.name AS reviewer_name
            FROM refund_requests r
            LEFT JOIN users u ON u.id = r.reviewed_by
            WHERE $where
            ORDER BY r.created_at DESC
        ");
        $stmt->execute($params);
        $refunds = $stmt->fetchAll();

        json_response(['success' => true, 'refunds' => $refunds]);
    }

    /** GET /api/refunds/pending */
    public function pending(): void
    {
        $this->auth->requireAuth();
        $db = Database::getInstance();

        $stmt = $db->query("
            SELECT r.*, t.subject AS ticket_subject
            FROM refund_requests r
            JOIN tickets t ON t.ticket_id = r.ticket_id
            WHERE r.status = 'pending'
            ORDER BY r.created_at ASC
        ");
        $refunds = $stmt->fetchAll();

        json_response(['success' => true, 'refunds' => $refunds, 'count' => count($refunds)]);
    }

    /** POST /api/refunds/{id}/approve */
    public function approve(int $refundId): void
    {
        $user = $this->auth->requireAdmin();
        $this->updateStatus($refundId, 'approved', $user['id']);
    }

    /** POST /api/refunds/{id}/reject */
    public function reject(int $refundId): void
    {
        $user = $this->auth->requireAdmin();
        $this->updateStatus($refundId, 'rejected', $user['id']);
    }

    private function updateStatus(int $refundId, string $status, int $reviewerId): void
    {
        $db   = Database::getInstance();
        $body = request_body();

        $stmt = $db->prepare("SELECT * FROM refund_requests WHERE id = ? LIMIT 1");
        $stmt->execute([$refundId]);
        $refund = $stmt->fetch();

        if (!$refund) {
            json_response(['success' => false, 'error' => 'Refund request not found.'], 404);
        }

        if ($refund['status'] !== 'pending') {
            json_response([
                'success' => false,
                'error'   => "Refund request is already {$refund['status']}. Cannot change status.",
            ], 409);
        }

        $approvedAmount = $status === 'approved'
            ? (float)($body['approved_amount_inr'] ?? $refund['requested_amount_inr'])
            : null;

        // Safety: approved amount cannot exceed requested amount
        if ($approvedAmount !== null && $approvedAmount > (float)$refund['requested_amount_inr']) {
            json_response([
                'success' => false,
                'error'   => 'Approved amount cannot exceed requested amount.',
            ], 422);
        }

        $stmt = $db->prepare("
            UPDATE refund_requests
            SET status = ?, approved_amount_inr = ?, reviewed_by = ?, reviewed_at = NOW(), updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$status, $approvedAmount, $reviewerId, $refundId]);

        // Audit
        $db->prepare("INSERT INTO audit_events (ticket_id, actor_id, event_type, event_data) VALUES (?, ?, ?, ?)")
           ->execute([
               $refund['ticket_id'],
               $reviewerId,
               'refund_' . $status,
               json_encode(['refund_id' => $refundId, 'amount' => $approvedAmount]),
           ]);

        Logger::info("Refund $status", ['refund_id' => $refundId, 'reviewer' => $reviewerId]);

        json_response([
            'success' => true,
            'message' => "Refund request has been $status.",
            'refund_id' => $refundId,
            'status' => $status,
        ]);
    }
}


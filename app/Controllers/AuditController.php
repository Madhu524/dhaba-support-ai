<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Middleware\AuthMiddleware;

class AuditController
{
    private AuthMiddleware $auth;

    public function __construct()
    {
        $this->auth = new AuthMiddleware();
    }

    /** GET /api/audit-events */
    public function index(): void
    {
        $this->auth->requireAdmin();
        $db = Database::getInstance();

        $page    = max(1, (int)($_GET['page']     ?? 1));
        $perPage = min(100, (int)($_GET['per_page'] ?? 20));
        $offset  = ($page - 1) * $perPage;
        $type    = $_GET['event_type'] ?? '';

        $where  = '1=1';
        $params = [];
        if (!empty($type)) {
            $where    = 'e.event_type = ?';
            $params[] = $type;
        }

        $stmt = $db->prepare("
            SELECT SQL_CALC_FOUND_ROWS e.id, e.ticket_id, e.actor_id, u.name AS actor_name,
                   e.event_type, e.event_data, e.created_at
            FROM audit_events e
            LEFT JOIN users u ON u.id = e.actor_id
            WHERE $where
            ORDER BY e.created_at DESC
            LIMIT $perPage OFFSET $offset
        ");
        $stmt->execute($params);
        $events = $stmt->fetchAll();
        $total  = (int)$db->query("SELECT FOUND_ROWS()")->fetchColumn();

        json_response([
            'success'   => true,
            'events'    => $events,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => max(1, (int)ceil($total / $perPage)),
        ]);
    }
}


<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Helpers\Logger;

/**
 * TicketService — ticket ingestion and retrieval.
 */
class TicketService
{
    /**
     * Import a ticket from a JSON payload (e.g. from the 12-ticket fixture).
     */
    public function import(array $data): array
    {
        $validation = $this->validate($data);
        if (!$validation['valid']) {
            return ['success' => false, 'errors' => $validation['errors']];
        }

        $db = Database::getInstance();

        // Idempotency: skip if ticket_id already exists
        $stmt = $db->prepare("SELECT id FROM tickets WHERE ticket_id = ? LIMIT 1");
        $stmt->execute([$data['ticket_id']]);
        if ($stmt->fetch()) {
            return ['success' => true, 'message' => 'Ticket already exists.', 'ticket_id' => $data['ticket_id'], 'created' => false];
        }

        // Normalise received_at
        $receivedAt = $this->normaliseDateTime($data['received_at'] ?? date('Y-m-d H:i:s'));

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("
                INSERT INTO tickets (ticket_id, received_at, subject, body, app_opens_since_renewal, status)
                VALUES (?, ?, ?, ?, ?, 'new')
            ");
            $stmt->execute([
                $data['ticket_id'],
                $receivedAt,
                $data['subject'],
                $data['body'],
                (int)($data['app_opens_since_renewal'] ?? 0),
            ]);

            // Import purchases
            if (!empty($data['purchases'])) {
                $this->importPurchases($db, $data['ticket_id'], $data['purchases']);
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            Logger::error('Ticket import failed', ['ticket_id' => $data['ticket_id'], 'error' => $e->getMessage()]);
            return ['success' => false, 'error' => 'Database error during import.'];
        }

        return [
            'success'   => true,
            'message'   => 'Ticket imported successfully.',
            'ticket_id' => $data['ticket_id'],
            'created'   => true,
        ];
    }

    private function importPurchases(\PDO $db, string $ticketId, array $purchases): void
    {
        $stmt = $db->prepare("
            INSERT IGNORE INTO purchases
                (ticket_id, purchase_id, purchase_type, amount_inr, status, purchased_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        foreach ($purchases as $p) {
            $validStatuses = ['success', 'failed', 'initiated', 'refunded', 'pending'];
            $status = in_array($p['status'] ?? '', $validStatuses) ? $p['status'] : 'pending';

            $stmt->execute([
                $ticketId,
                $p['purchase_id'],
                $p['purchase_type'],
                (float)($p['amount_inr'] ?? 0),
                $status,
                $this->normaliseDateTime($p['purchased_at'] ?? date('Y-m-d H:i:s')),
            ]);
        }
    }

    public function list(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $db      = Database::getInstance();
        $where   = ['1=1'];
        $params  = [];

        if (!empty($filters['status'])) {
            $where[]  = 'status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['search'])) {
            $where[]  = '(subject LIKE ? OR ticket_id LIKE ?)';
            $like     = '%' . $filters['search'] . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $whereSql = implode(' AND ', $where);
        $offset   = ($page - 1) * $perPage;

        $stmt = $db->prepare(
            "SELECT SQL_CALC_FOUND_ROWS id, ticket_id, received_at, subject, app_opens_since_renewal, status, created_at
             FROM tickets WHERE $whereSql ORDER BY received_at DESC LIMIT $perPage OFFSET $offset"
        );
        $stmt->execute($params);
        $tickets = $stmt->fetchAll();

        $total = (int)$db->query("SELECT FOUND_ROWS()")->fetchColumn();

        return [
            'tickets'   => $tickets,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => max(1, (int)ceil($total / $perPage)),
        ];
    }

    public function findById(string $ticketId): ?array
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM tickets WHERE ticket_id = ? LIMIT 1");
        $stmt->execute([$ticketId]);
        return $stmt->fetch() ?: null;
    }

    public function getLatestTriage(string $ticketId): ?array
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare(
            "SELECT * FROM triage_results WHERE ticket_id = ? ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute([$ticketId]);
        return $stmt->fetch() ?: null;
    }

    public function getTriageHistory(string $ticketId): array
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare(
            "SELECT * FROM triage_results WHERE ticket_id = ? ORDER BY created_at DESC"
        );
        $stmt->execute([$ticketId]);
        return $stmt->fetchAll();
    }

    public function getPurchases(string $ticketId): array
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare(
            "SELECT * FROM purchases WHERE ticket_id = ? ORDER BY purchased_at ASC"
        );
        $stmt->execute([$ticketId]);
        return $stmt->fetchAll();
    }

    private function validate(array $data): array
    {
        $errors = [];

        if (empty($data['ticket_id'])) {
            $errors[] = 'ticket_id is required.';
        } elseif (!preg_match('/^[A-Z0-9\-]{2,30}$/', $data['ticket_id'])) {
            $errors[] = 'ticket_id format is invalid (alphanumeric and dashes, 2–30 chars).';
        }

        if (empty($data['subject'])) {
            $errors[] = 'subject is required.';
        }

        if (empty($data['body'])) {
            $errors[] = 'body is required.';
        }

        if (isset($data['purchases'])) {
            if (!is_array($data['purchases'])) {
                $errors[] = 'purchases must be an array.';
            } else {
                foreach ($data['purchases'] as $i => $p) {
                    if (empty($p['purchase_id'])) {
                        $errors[] = "purchases[$i].purchase_id is required.";
                    }
                    if (empty($p['purchase_type'])) {
                        $errors[] = "purchases[$i].purchase_type is required.";
                    }
                    if (!isset($p['amount_inr']) || !is_numeric($p['amount_inr'])) {
                        $errors[] = "purchases[$i].amount_inr must be numeric.";
                    } elseif ((float)$p['amount_inr'] < 0) {
                        $errors[] = "purchases[$i].amount_inr cannot be negative.";
                    }
                    $validStatuses = ['success', 'failed', 'initiated', 'refunded', 'pending'];
                    if (empty($p['status']) || !in_array($p['status'], $validStatuses)) {
                        $errors[] = "purchases[$i].status must be one of: " . implode(', ', $validStatuses);
                    }
                }
            }
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }

    private function normaliseDateTime(string $dt): string
    {
        // Convert ISO 8601 with Z/offset to MySQL datetime
        try {
            $date = new \DateTime($dt);
            $date->setTimezone(new \DateTimeZone('UTC'));
            return $date->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return date('Y-m-d H:i:s');
        }
    }
}


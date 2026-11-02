<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\TicketService;
use App\Services\TriageService;
use App\Middleware\AuthMiddleware;

class TicketController
{
    private TicketService  $tickets;
    private TriageService  $triage;
    private AuthMiddleware $auth;

    public function __construct()
    {
        $this->tickets = new TicketService();
        $this->triage  = new TriageService();
        $this->auth    = new AuthMiddleware();
    }

    /** POST /api/tickets */
    public function store(): void
    {
        $user = $this->auth->requireAuth();
        $data = request_body();

        if (empty($data)) {
            json_response(['success' => false, 'error' => 'Request body is empty.'], 422);
        }

        $result = $this->tickets->import($data);
        $status = $result['success'] ? ($result['created'] ?? true ? 201 : 200) : 422;
        json_response($result, $status);
    }

    /** GET /api/tickets */
    public function index(): void
    {
        $this->auth->requireAuth();

        $filters = [
            'status' => $_GET['status'] ?? '',
            'search' => $_GET['search'] ?? '',
        ];
        $page    = max(1, (int)($_GET['page']     ?? 1));
        $perPage = min(100, max(1, (int)($_GET['per_page'] ?? 20)));

        $result = $this->tickets->list($filters, $page, $perPage);
        json_response(['success' => true, 'data' => $result]);
    }

    /** GET /api/tickets/{id} */
    public function show(string $ticketId): void
    {
        $this->auth->requireAuth();

        $ticket = $this->tickets->findById($ticketId);
        if (!$ticket) {
            json_response(['success' => false, 'error' => 'Ticket not found.'], 404);
        }

        $purchases = $this->tickets->getPurchases($ticketId);
        $triage    = $this->tickets->getLatestTriage($ticketId);

        json_response([
            'success'   => true,
            'ticket'    => $ticket,
            'purchases' => $purchases,
            'triage'    => $triage,
        ]);
    }

    /** GET /api/tickets/{id}/purchases */
    public function purchases(string $ticketId): void
    {
        $this->auth->requireAuth();

        $ticket = $this->tickets->findById($ticketId);
        if (!$ticket) {
            json_response(['success' => false, 'error' => 'Ticket not found.'], 404);
        }

        $purchases = $this->tickets->getPurchases($ticketId);
        json_response(['success' => true, 'purchases' => $purchases]);
    }

    /** POST /api/tickets/{id}/triage */
    public function triggerTriage(string $ticketId): void
    {
        $user = $this->auth->requireAuth();

        $ticket = $this->tickets->findById($ticketId);
        if (!$ticket) {
            json_response(['success' => false, 'error' => 'Ticket not found.'], 404);
        }

        $result = $this->triage->triage($ticketId, $user['id']);
        $status = $result['success'] ? 200 : 500;
        json_response($result, $status);
    }

    /** GET /api/tickets/{id}/triage-history */
    public function triageHistory(string $ticketId): void
    {
        $this->auth->requireAuth();

        $ticket = $this->tickets->findById($ticketId);
        if (!$ticket) {
            json_response(['success' => false, 'error' => 'Ticket not found.'], 404);
        }

        $history = $this->tickets->getTriageHistory($ticketId);
        json_response(['success' => true, 'history' => $history]);
    }

    /** POST /api/tickets/{id}/retry-triage */
    public function retryTriage(string $ticketId): void
    {
        $this->triggerTriage($ticketId);
    }

    /** POST /triage (legacy / Postman shortcut — accepts full ticket payload) */
    public function triageDirect(): void
    {
        $data = request_body();

        if (empty($data['ticket_id'])) {
            json_response(['success' => false, 'error' => 'ticket_id is required.'], 422);
        }

        // Import the ticket if it does not yet exist
        $importResult = $this->tickets->import($data);
        if (!$importResult['success']) {
            json_response($importResult, 422);
        }

        // Determine actor (optional auth)
        $actorId = null;
        $token   = \App\Services\AuthService::extractBearerToken();
        if (!empty($token)) {
            $authService = new \App\Services\AuthService();
            $user        = $authService->validateToken($token);
            $actorId     = $user ? (int)$user['id'] : null;
        }

        $result = $this->triage->triage($data['ticket_id'], $actorId);
        json_response($result, $result['success'] ? 200 : 500);
    }
}


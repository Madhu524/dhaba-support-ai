<?php
declare(strict_types=1);

/**
 * Dhaba Support AI — API Router
 *
 * Pattern matching is done using PREG_SPLIT on the path segments.
 * Supports static and single-segment dynamic routes.
 */

use App\Controllers\AuthController;
use App\Controllers\TicketController;
use App\Controllers\RefundController;
use App\Controllers\DashboardController;
use App\Controllers\ReportController;
use App\Controllers\AuditController;
use App\Controllers\SystemController;

function route(string $method, string $uri): void
{
    // Normalise URI: remove query string and leading slash
    $path = parse_url($uri, PHP_URL_PATH) ?? '/';
    $path = rtrim($path, '/');

    // Strip the base path prefix so routes work under subdirectory installations
    $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    if ($scriptDir !== '' && str_starts_with($path, $scriptDir)) {
        $path = substr($path, strlen($scriptDir));
    }
    if ($path === '') {
        $path = '/';
    }

    $segments = explode('/', ltrim($path, '/'));

    // ── System Health ──────────────────────────────────────────────────────
    if ($method === 'GET' && $path === '/api/health') {
        (new SystemController())->health(); return;
    }
    if ($method === 'GET' && $path === '/api/ready') {
        (new SystemController())->ready(); return;
    }

    // ── Direct triage endpoint (Postman friendly) ──────────────────────────
    if ($method === 'POST' && $path === '/triage') {
        (new TicketController())->triageDirect(); return;
    }
    if ($method === 'POST' && $path === '/api/triage') {
        (new TicketController())->triageDirect(); return;
    }

    // ── Auth ───────────────────────────────────────────────────────────────
    if ($method === 'POST' && $path === '/api/auth/login') {
        (new AuthController())->login(); return;
    }
    if ($method === 'POST' && $path === '/api/auth/logout') {
        (new AuthController())->logout(); return;
    }
    if ($method === 'GET' && $path === '/api/auth/me') {
        (new AuthController())->me(); return;
    }

    // ── Tickets ────────────────────────────────────────────────────────────
    if ($method === 'POST' && $path === '/api/tickets') {
        (new TicketController())->store(); return;
    }
    if ($method === 'GET' && $path === '/api/tickets') {
        (new TicketController())->index(); return;
    }

    // Ticket sub-routes: /api/tickets/{id}/...
    if (count($segments) >= 3 && $segments[0] === 'api' && $segments[1] === 'tickets') {
        $ticketId = $segments[2];
        $sub      = $segments[3] ?? '';
        $ctrl     = new TicketController();

        if ($method === 'GET'  && $sub === '')              { $ctrl->show($ticketId);         return; }
        if ($method === 'GET'  && $sub === 'purchases')     { $ctrl->purchases($ticketId);    return; }
        if ($method === 'POST' && $sub === 'triage')        { $ctrl->triggerTriage($ticketId);return; }
        if ($method === 'GET'  && $sub === 'triage-history'){ $ctrl->triageHistory($ticketId);return; }
        if ($method === 'POST' && $sub === 'retry-triage')  { $ctrl->retryTriage($ticketId);  return; }
    }

    // ── Refunds ────────────────────────────────────────────────────────────
    if ($method === 'GET' && $path === '/api/refunds') {
        (new RefundController())->index(); return;
    }
    if ($method === 'GET' && $path === '/api/refunds/pending') {
        (new RefundController())->pending(); return;
    }
    // /api/refunds/{id}/approve|reject
    if (count($segments) >= 4 && $segments[0] === 'api' && $segments[1] === 'refunds') {
        $refundId = (int)$segments[2];
        $action   = $segments[3] ?? '';
        if ($method === 'POST' && $action === 'approve') {
            (new RefundController())->approve($refundId); return;
        }
        if ($method === 'POST' && $action === 'reject') {
            (new RefundController())->reject($refundId); return;
        }
    }

    // ── Dashboard ──────────────────────────────────────────────────────────
    if ($method === 'GET' && $path === '/api/dashboard/summary') {
        (new DashboardController())->summary(); return;
    }
    if ($method === 'GET' && $path === '/api/dashboard/tickets-by-category') {
        (new DashboardController())->byCategory(); return;
    }
    if ($method === 'GET' && $path === '/api/dashboard/tickets-by-severity') {
        (new DashboardController())->bySeverity(); return;
    }
    if ($method === 'GET' && $path === '/api/dashboard/refund-summary') {
        (new DashboardController())->refundSummary(); return;
    }
    if ($method === 'GET' && $path === '/api/dashboard/recent-tickets') {
        (new DashboardController())->recentTickets(); return;
    }

    // ── Reports ────────────────────────────────────────────────────────────
    if ($method === 'GET' && $path === '/api/reports/triage') {
        (new ReportController())->triage(); return;
    }
    if ($method === 'GET' && $path === '/api/reports/refunds') {
        (new ReportController())->refunds(); return;
    }
    if ($method === 'GET' && $path === '/api/reports/export') {
        (new ReportController())->export(); return;
    }

    // ── Audit ──────────────────────────────────────────────────────────────
    if ($method === 'GET' && $path === '/api/audit-events') {
        (new AuditController())->index(); return;
    }

    // ── 404 ────────────────────────────────────────────────────────────────
    json_response(['success' => false, 'error' => "Route not found: $method $path"], 404);
}


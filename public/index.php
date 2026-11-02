<?php
declare(strict_types=1);

/**
 * Dhaba Support AI — Public Entry Point
 *
 * All HTTP requests pass through this file.
 * Apache rewrites non-file, non-directory requests to index.php.
 */

// ── Bootstrap ─────────────────────────────────────────────────────────────────
require_once dirname(__DIR__) . '/bootstrap.php';

// ── CORS ──────────────────────────────────────────────────────────────────────
$allowedOrigins = ['http://localhost', 'http://127.0.0.1'];
$origin         = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origin, $allowedOrigins, true) || str_starts_with($origin, 'http://localhost')) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    header('Access-Control-Allow-Origin: http://localhost');
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Max-Age: 3600');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Content-Type default ──────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');

// ── Request size guard ────────────────────────────────────────────────────────
$maxBody = 1024 * 1024; // 1 MB
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxBody) {
    json_response(['success' => false, 'error' => 'Request body too large.'], 413);
}

// ── Determine requested URI ───────────────────────────────────────────────────
$requestUri    = $_SERVER['REQUEST_URI']    ?? '/';
$requestMethod = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// ── Serve dashboard SPA for non-API browser requests ─────────────────────────
$parsed = parse_url($requestUri, PHP_URL_PATH) ?? '/';
$scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$localPath = $scriptDir !== '' ? substr($parsed, strlen($scriptDir)) : $parsed;
$localPath = $localPath === '' ? '/' : $localPath;

// If request is for the dashboard root or dashboard pages, serve the HTML dashboard
if (
    !str_starts_with($localPath, '/api') &&
    !str_starts_with($localPath, '/triage') &&
    $localPath !== '/api/health' &&
    $localPath !== '/api/ready'
) {
    $dashboardFile = __DIR__ . '/dashboard/index.html';
    if (file_exists($dashboardFile) && ($requestMethod === 'GET' || $requestMethod === 'HEAD')) {
        header('Content-Type: text/html; charset=utf-8');
        if ($requestMethod === 'GET') {
            readfile($dashboardFile);
        }
        exit;
    }
}

// ── Route ─────────────────────────────────────────────────────────────────────
require_once dirname(__DIR__) . '/routes/api.php';
route($requestMethod, $requestUri);


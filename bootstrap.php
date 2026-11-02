<?php
declare(strict_types=1);

/**
 * Dhaba Support AI - Bootstrap
 * Loads environment, autoloader, and application configuration.
 */

if (!defined('BASE_PATH')) define('BASE_PATH', __DIR__);
if (!defined('APP_PATH'))  define('APP_PATH',  __DIR__ . '/app');
if (!defined('DB_PATH'))   define('DB_PATH',   __DIR__ . '/database');

// ── Load .env file ────────────────────────────────────────────────────────────
$envFile = BASE_PATH . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_contains($line, '=')) {
            [$key, $val] = explode('=', $line, 2);
            $key = trim($key);
            $val = trim($val, " \t\n\r\0\x0B\"'");
            if (!array_key_exists($key, $_ENV)) {
                $_ENV[$key]    = $val;
                putenv("$key=$val");
            }
        }
    }
}

// ── Autoloader ────────────────────────────────────────────────────────────────
// Support Composer autoloading when vendor/ exists, otherwise use simple PSR-4.
$composerAutoload = BASE_PATH . '/vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
} else {
    spl_autoload_register(function (string $class): void {
        // Strip top-level namespace "App\"
        $relative = str_replace('App\\', '', $class);
        $relative = str_replace('\\', DIRECTORY_SEPARATOR, $relative);
        $file     = APP_PATH . DIRECTORY_SEPARATOR . $relative . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    });
}

// ── Helpers ───────────────────────────────────────────────────────────────────
require_once APP_PATH . '/Helpers/functions.php';

// ── Error handling ────────────────────────────────────────────────────────────
$debug = env('APP_DEBUG', 'false') === 'true';

set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline) use ($debug): bool {
    if (!($errno & error_reporting())) {
        return false;
    }
    $msg = $debug ? "PHP Error [$errno]: $errstr in $errfile:$errline" : 'An internal error occurred.';
    App\Helpers\Logger::error("PHP Error [$errno]: $errstr in $errfile:$errline");
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $msg]);
    }
    return true;
});

set_exception_handler(function (Throwable $e) use ($debug): void {
    $msg = $debug ? $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() : 'An unexpected error occurred.';
    App\Helpers\Logger::error('Unhandled exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $msg]);
    }
});


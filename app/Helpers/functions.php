<?php
declare(strict_types=1);

if (!function_exists('env')) {
    /**
     * Read an environment variable with an optional default.
     */
    function env(string $key, string $default = ''): string
    {
        $val = $_ENV[$key] ?? getenv($key);
        return ($val !== false && $val !== null) ? (string)$val : $default;
    }
}

if (!function_exists('json_response')) {
    /**
     * Output a JSON response and exit.
     */
    function json_response(array $data, int $status = 200): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
}

if (!function_exists('request_body')) {
    /**
     * Parse the JSON request body, returning an array.
     */
    function request_body(): array
    {
        $raw = file_get_contents('php://input');
        if (empty($raw)) {
            return [];
        }
        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            json_response(['success' => false, 'error' => 'Invalid JSON body.'], 400);
        }
        return is_array($data) ? $data : [];
    }
}

if (!function_exists('sanitize_string')) {
    /**
     * Strip tags and trim a string (does NOT truncate business content).
     */
    function sanitize_string(string $input, int $maxLen = 10000): string
    {
        return mb_substr(trim(strip_tags($input)), 0, $maxLen);
    }
}

if (!function_exists('generate_token')) {
    /**
     * Generate a cryptographically random token.
     */
    function generate_token(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }
}

if (!function_exists('days_ago')) {
    /**
     * Return a DateTime object representing $n days ago.
     */
    function days_ago(int $n): \DateTime
    {
        return new \DateTime("-$n days");
    }
}


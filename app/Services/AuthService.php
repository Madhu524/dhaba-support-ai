<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Helpers\Logger;

/**
 * AuthService — token-based authentication.
 *
 * Tokens are stored as SHA-256 hashes. The plaintext token is returned only
 * once at login and is never persisted. Passwords are hashed with bcrypt.
 */
class AuthService
{
    public function login(string $email, string $password): array
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare(
            "SELECT id, name, email, password_hash, role FROM users WHERE email = ? AND is_active = 1 LIMIT 1"
        );
        $stmt->execute([mb_strtolower(trim($email))]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            Logger::warn('Failed login attempt', ['email_domain' => substr(strrchr($email, '@') ?: '', 1)]);
            return ['success' => false, 'error' => 'Invalid credentials.'];
        }

        // Generate plaintext token (returned once), store hash
        $plainToken = generate_token(32);
        $tokenHash  = hash('sha256', $plainToken);
        $expiresAt  = gmdate('Y-m-d H:i:s', time() + (int)env('TOKEN_EXPIRY_HOURS', '24') * 3600);

        $db->prepare(
            "INSERT INTO api_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)"
        )->execute([$user['id'], $tokenHash, $expiresAt]);

        Logger::info('User logged in', ['user_id' => $user['id'], 'role' => $user['role']]);

        return [
            'success'    => true,
            'token_type' => 'Bearer',
            'token'      => $plainToken,
            'expires_at' => $expiresAt,
            'user'       => [
                'id'    => $user['id'],
                'name'  => $user['name'],
                'email' => $user['email'],
                'role'  => $user['role'],
            ],
        ];
    }

    public function logout(string $plainToken): void
    {
        $db        = Database::getInstance();
        $tokenHash = hash('sha256', $plainToken);
        $db->prepare("DELETE FROM api_tokens WHERE token_hash = ?")->execute([$tokenHash]);
    }

    /**
     * Validate a token and return the associated user, or null.
     */
    public function validateToken(string $plainToken): ?array
    {
        if (empty($plainToken)) {
            return null;
        }

        $db        = Database::getInstance();
        $tokenHash = hash('sha256', $plainToken);

        $stmt = $db->prepare("
            SELECT u.id, u.name, u.email, u.role
            FROM api_tokens t
            JOIN users u ON u.id = t.user_id
            WHERE t.token_hash = ?
              AND t.expires_at > UTC_TIMESTAMP()
              AND u.is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$tokenHash]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    /**
     * Extract the Bearer token from the Authorization header.
     */
    public static function extractBearerToken(): string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if ($header === '') {
            $header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        }
        if ($header === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    $header = $value;
                    break;
                }
            }
        }
        if ($header === '') {
            $header = getenv('HTTP_AUTHORIZATION') ?: '';
        }

        if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return trim($m[1]);
        }
        return '';
    }
}

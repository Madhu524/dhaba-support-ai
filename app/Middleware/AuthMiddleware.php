<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Services\AuthService;

/**
 * Authentication middleware.
 * Extracts, validates the Bearer token and returns the user array or fails.
 */
class AuthMiddleware
{
    private AuthService $auth;

    public function __construct()
    {
        $this->auth = new AuthService();
    }

    /**
     * Require authentication. Returns the authenticated user or sends 401.
     */
    public function requireAuth(): array
    {
        $token = AuthService::extractBearerToken();
        if (empty($token)) {
            json_response(['success' => false, 'error' => 'Authentication required. Provide a Bearer token.'], 401);
        }

        $user = $this->auth->validateToken($token);
        if (!$user) {
            json_response(['success' => false, 'error' => 'Invalid or expired token.'], 401);
        }

        return $user;
    }

    /**
     * Require admin role.
     */
    public function requireAdmin(): array
    {
        $user = $this->requireAuth();
        if ($user['role'] !== 'admin') {
            json_response(['success' => false, 'error' => 'Administrator access required.'], 403);
        }
        return $user;
    }
}


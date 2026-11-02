<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;

class AuthController
{
    private AuthService $auth;

    public function __construct()
    {
        $this->auth = new AuthService();
    }

    /** POST /api/auth/login */
    public function login(): void
    {
        $body  = request_body();
        $email = trim($body['email'] ?? '');
        $pass  = $body['password'] ?? '';

        if (empty($email) || empty($pass)) {
            json_response(['success' => false, 'error' => 'email and password are required.'], 422);
        }

        $result = $this->auth->login($email, $pass);

        if (!$result['success']) {
            json_response($result, 401);
        }

        json_response($result, 200);
    }

    /** POST /api/auth/logout */
    public function logout(): void
    {
        $token = AuthService::extractBearerToken();
        if (!empty($token)) {
            $this->auth->logout($token);
        }
        json_response(['success' => true, 'message' => 'Logged out successfully.']);
    }

    /** GET /api/auth/me */
    public function me(): void
    {
        $token = AuthService::extractBearerToken();
        $user  = $this->auth->validateToken($token);

        if (!$user) {
            json_response(['success' => false, 'error' => 'Not authenticated.'], 401);
        }

        json_response(['success' => true, 'user' => $user]);
    }
}


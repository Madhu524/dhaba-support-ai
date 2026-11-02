<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;

class SystemController
{
    /** GET /api/health */
    public function health(): void
    {
        json_response([
            'success'  => true,
            'status'   => 'ok',
            'service'  => env('APP_NAME', 'Dhaba Support AI'),
            'time'     => date('c'),
        ]);
    }

    /** GET /api/ready */
    public function ready(): void
    {
        $dbOk = false;
        $dbMsg = 'ok';
        try {
            Database::getInstance()->query('SELECT 1');
            $dbOk = true;
        } catch (\Throwable $e) {
            $dbMsg = 'unavailable';
        }

        $status = $dbOk ? 200 : 503;
        json_response([
            'success'  => $dbOk,
            'status'   => $dbOk ? 'ready' : 'not_ready',
            'checks'   => ['database' => $dbMsg],
        ], $status);
    }
}


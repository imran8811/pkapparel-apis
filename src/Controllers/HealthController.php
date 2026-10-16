<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use Throwable;

final class HealthController
{
    public function __construct(private readonly Database $db)
    {
    }

    /** GET /v1/health: is the API up, and can it reach the database? */
    public function show(Request $request): Response
    {
        try {
            $databaseOk = (int) $this->db->fetchValue('SELECT 1') === 1;
        } catch (Throwable) {
            $databaseOk = false;
        }

        return Response::data(
            [
                'status' => $databaseOk ? 'ok' : 'degraded',
                'database' => $databaseOk ? 'ok' : 'unavailable',
                'time' => gmdate(DATE_ATOM),
            ],
            [],
            $databaseOk ? 200 : 503,
        );
    }
}

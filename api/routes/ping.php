<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * GET /api/ping
 * Cek kesehatan API + konektivitas database. Tidak butuh auth.
 */
function handlePing(): void
{
    try {
        $pdo = getDbConnection();
        $pdo->query('SELECT 1');

        jsonResponse(true, ['db' => 'connected'], 'ok');
    } catch (Throwable $e) {
        jsonResponse(false, ['db' => 'error'], 'DB error', 500);
    }
}

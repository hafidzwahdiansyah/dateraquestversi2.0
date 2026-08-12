<?php

declare(strict_types=1);

require_once __DIR__ . '/response.php';

/**
 * Pastikan ada sesi login aktif. Kalau belum login, kirim 401 JSON dan hentikan eksekusi.
 */
function requireLogin(): void
{
    if (empty($_SESSION['team_id'])) {
        jsonResponse(false, null, 'Belum login', 401);
    }
}

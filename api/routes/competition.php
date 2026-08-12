<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * GET /api/competitions
 * Daftar semua kategori kompetisi. Publik, tidak butuh login.
 */
function handleListCompetitions(): void
{
    $pdo  = getDbConnection();
    $stmt = $pdo->query('SELECT id, nama, slug, deskripsi, min_members, max_members FROM competitions ORDER BY id ASC');
    $rows = $stmt->fetchAll();

    $competitions = array_map(static function (array $row): array {
        $row['id']          = (int) $row['id'];
        $row['min_members'] = (int) $row['min_members'];
        $row['max_members'] = (int) $row['max_members'];
        return $row;
    }, $rows);

    jsonResponse(true, $competitions, 'ok');
}

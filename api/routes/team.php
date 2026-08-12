<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/middleware.php';

/**
 * GET /api/me
 * Info lengkap akun tim yang sedang login: data tim, anggota, dan status bukti bayar.
 */
function handleMe(): void
{
    requireLogin();

    $pdo    = getDbConnection();
    $teamId = (int) $_SESSION['team_id'];

    $stmt = $pdo->prepare(
        'SELECT t.id, t.nama_tim, t.username, t.status_registrasi,
                c.id AS competition_id, c.nama AS competition_nama, c.slug AS competition_slug
         FROM teams t
         JOIN competitions c ON c.id = t.competition_id
         WHERE t.id = ?
         LIMIT 1'
    );
    $stmt->execute([$teamId]);
    $team = $stmt->fetch();

    if (!$team) {
        jsonResponse(false, null, 'Tim tidak ditemukan', 404);
    }

    $memberStmt = $pdo->prepare(
        'SELECT nama_lengkap, institusi, nim, nomor_hp, is_ketua
         FROM team_members WHERE team_id = ? ORDER BY is_ketua DESC, id ASC'
    );
    $memberStmt->execute([$teamId]);
    $members = array_map(static function (array $member): array {
        $member['is_ketua'] = (bool) $member['is_ketua'];
        return $member;
    }, $memberStmt->fetchAll());

    $paymentStmt = $pdo->prepare(
        'SELECT status, file_path, catatan_admin, created_at
         FROM payment_proofs WHERE team_id = ? LIMIT 1'
    );
    $paymentStmt->execute([$teamId]);
    $payment = $paymentStmt->fetch();

    $paymentProof = null;
    if ($payment) {
        $paymentProof = [
            'status'        => $payment['status'],
            'file_path'     => $payment['file_path'],
            'catatan_admin' => $payment['catatan_admin'],
            'uploaded_at'   => $payment['created_at'],
        ];
    }

    jsonResponse(true, [
        'team_id'  => (int) $team['id'],
        'nama_tim' => $team['nama_tim'],
        'username' => $team['username'],
        'competition' => [
            'id'   => (int) $team['competition_id'],
            'nama' => $team['competition_nama'],
            'slug' => $team['competition_slug'],
        ],
        'competition_id'    => (int) $team['competition_id'],
        'status_registrasi' => $team['status_registrasi'],
        'members'           => $members,
        'payment_proof'     => $paymentProof,
    ], 'ok');
}

/**
 * POST /api/payment/upload
 * Upload bukti pembayaran (multipart/form-data, field "file").
 */
function handlePaymentUpload(): void
{
    requireLogin();

    $pdo    = getDbConnection();
    $teamId = (int) $_SESSION['team_id'];

    $stmt = $pdo->prepare('SELECT status_registrasi FROM teams WHERE id = ? LIMIT 1');
    $stmt->execute([$teamId]);
    $team = $stmt->fetch();

    if (!$team) {
        jsonResponse(false, null, 'Tim tidak ditemukan', 404);
    }

    if ($team['status_registrasi'] !== 'pending') {
        jsonResponse(false, null, 'Tim ini tidak bisa mengupload bukti pembayaran saat ini', 403);
    }

    if (!isset($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
        jsonResponse(false, null, 'Format file harus jpg, png, atau pdf', 422);
    }

    $file = $_FILES['file'];

    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        jsonResponse(false, null, 'Ukuran file maksimal 2MB', 413);
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(false, null, 'Gagal mengupload file', 422);
    }

    $maxSize = 2 * 1024 * 1024; // 2MB
    if ($file['size'] > $maxSize) {
        jsonResponse(false, null, 'Ukuran file maksimal 2MB', 413);
    }

    $allowedMimeTypes = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'pdf'  => 'application/pdf',
    ];

    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!array_key_exists($extension, $allowedMimeTypes)) {
        jsonResponse(false, null, 'Format file harus jpg, png, atau pdf', 422);
    }

    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    if ($mimeType !== $allowedMimeTypes[$extension]) {
        jsonResponse(false, null, 'Format file harus jpg, png, atau pdf', 422);
    }

    $filename      = uniqid('bukti_') . '.' . $extension;
    $relativePath  = 'bukti_pembayaran/' . $filename;
    $uploadDir     = __DIR__ . '/../uploads/bukti_pembayaran/';
    $destination   = $uploadDir . $filename;

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        jsonResponse(false, null, 'Gagal menyiapkan folder upload di server', 500);
    }

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        jsonResponse(false, null, 'Gagal menyimpan file di server', 500);
    }

    $upsert = $pdo->prepare(
        'INSERT INTO payment_proofs (team_id, file_path, status, catatan_admin)
         VALUES (?, ?, ?, NULL)
         ON DUPLICATE KEY UPDATE file_path = VALUES(file_path), status = VALUES(status), catatan_admin = NULL'
    );
    $upsert->execute([$teamId, $relativePath, 'pending']);

    jsonResponse(true, [
        'file'      => $filename,
        'file_path' => $relativePath,
        'status'    => 'pending',
    ], 'Bukti pembayaran berhasil diupload');
}

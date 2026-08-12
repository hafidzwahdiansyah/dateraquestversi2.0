<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * POST /api/auth/register
 * Membuat akun tim baru + anggotanya.
 */
function handleRegister(): void
{
    $body = getJsonBody();

    $namaTim       = trim((string) ($body['nama_tim'] ?? ''));
    $username      = trim((string) ($body['username'] ?? ''));
    $password      = (string) ($body['password'] ?? '');
    $competitionId = $body['competition_id'] ?? null;
    // TODO: Setelah Google OAuth aktif, ambil google_email dari $_SESSION (hasil
    // callback OAuth), bukan dari request body. Untuk sekarang diterima langsung
    // dari body karena Google OAuth belum tersedia (lihat catatan Tahap 3).
    $googleEmail = trim((string) ($body['google_email'] ?? ''));
    $members     = $body['members'] ?? null;

    if ($namaTim === '' || $username === '' || $password === '' || $googleEmail === '' || !is_array($members)) {
        jsonResponse(false, null, 'Data registrasi tidak lengkap', 422);
    }

    if (!is_numeric($competitionId)) {
        jsonResponse(false, null, 'competition_id tidak valid', 422);
    }
    $competitionId = (int) $competitionId;

    if (strlen($password) < 8) {
        jsonResponse(false, null, 'Password minimal 8 karakter', 422);
    }

    if (!filter_var($googleEmail, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(false, null, 'Format google_email tidak valid', 422);
    }

    $pdo = getDbConnection();

    $stmt = $pdo->prepare('SELECT id, min_members, max_members FROM competitions WHERE id = ?');
    $stmt->execute([$competitionId]);
    $competition = $stmt->fetch();

    if (!$competition) {
        jsonResponse(false, ['competition_id' => 'Kompetisi tidak ditemukan'], 'Data registrasi tidak valid', 422);
    }

    $minMembers = (int) $competition['min_members'];
    $maxMembers = (int) $competition['max_members'];
    $memberCount = count($members);

    if ($memberCount < $minMembers || $memberCount > $maxMembers) {
        jsonResponse(
            false,
            ['members' => "Kategori ini membutuhkan {$minMembers}-{$maxMembers} anggota"],
            'Data registrasi tidak valid',
            422
        );
    }

    $ketuaCount   = 0;
    $cleanMembers = [];

    foreach ($members as $index => $member) {
        if (!is_array($member)) {
            jsonResponse(false, ['members' => 'Data anggota ke-' . ($index + 1) . ' tidak valid'], 'Data registrasi tidak valid', 422);
        }

        $namaLengkap = trim((string) ($member['nama_lengkap'] ?? ''));
        $institusi   = trim((string) ($member['institusi'] ?? ''));
        $nim         = trim((string) ($member['nim'] ?? ''));
        $nomorHp     = trim((string) ($member['nomor_hp'] ?? ''));
        $isKetua     = (bool) ($member['is_ketua'] ?? false);

        if ($namaLengkap === '' || $institusi === '' || $nim === '' || $nomorHp === '') {
            jsonResponse(false, ['members' => 'Data anggota ke-' . ($index + 1) . ' belum lengkap'], 'Data registrasi tidak valid', 422);
        }

        if ($isKetua) {
            $ketuaCount++;
        }

        $cleanMembers[] = [
            'nama_lengkap' => $namaLengkap,
            'institusi'    => $institusi,
            'nim'          => $nim,
            'nomor_hp'     => $nomorHp,
            'is_ketua'     => $isKetua,
        ];
    }

    if ($ketuaCount !== 1) {
        jsonResponse(false, ['members' => 'Harus ada tepat satu anggota dengan is_ketua true'], 'Data registrasi tidak valid', 422);
    }

    $stmt = $pdo->prepare('SELECT id FROM teams WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        jsonResponse(false, null, 'Username sudah digunakan', 409);
    }

    $stmt = $pdo->prepare('SELECT id FROM teams WHERE google_email = ? LIMIT 1');
    $stmt->execute([$googleEmail]);
    if ($stmt->fetch()) {
        jsonResponse(false, null, 'Email Google sudah terdaftar', 409);
    }

    $passwordHash = password_hash($password, PASSWORD_BCRYPT);

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'INSERT INTO teams (competition_id, nama_tim, username, password, google_email, status_registrasi)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$competitionId, $namaTim, $username, $passwordHash, $googleEmail, 'pending']);

        $teamId = (int) $pdo->lastInsertId();

        $memberStmt = $pdo->prepare(
            'INSERT INTO team_members (team_id, nama_lengkap, institusi, nim, nomor_hp, is_ketua)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($cleanMembers as $member) {
            $memberStmt->execute([
                $teamId,
                $member['nama_lengkap'],
                $member['institusi'],
                $member['nim'],
                $member['nomor_hp'],
                $member['is_ketua'] ? 1 : 0,
            ]);
        }

        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();

        if ($e->getCode() === '23000') {
            jsonResponse(false, null, 'Username atau email Google sudah terdaftar', 409);
        }

        throw $e;
    }

    jsonResponse(true, [
        'team_id'           => $teamId,
        'nama_tim'          => $namaTim,
        'username'          => $username,
        'status_registrasi' => 'pending',
    ], 'Registrasi berhasil', 201);
}

/**
 * POST /api/auth/login
 * Login dengan username + password, membuat sesi login.
 */
function handleLogin(): void
{
    $body     = getJsonBody();
    $username = trim((string) ($body['username'] ?? ''));
    $password = (string) ($body['password'] ?? '');

    if ($username === '' || $password === '') {
        jsonResponse(false, null, 'Username dan password wajib diisi', 422);
    }

    $pdo  = getDbConnection();
    $stmt = $pdo->prepare(
        'SELECT id, nama_tim, username, password, competition_id, status_registrasi
         FROM teams WHERE username = ? LIMIT 1'
    );
    $stmt->execute([$username]);
    $team = $stmt->fetch();

    if (!$team || !password_verify($password, $team['password'])) {
        jsonResponse(false, null, 'Username atau password salah', 401);
    }

    $_SESSION['team_id']           = (int) $team['id'];
    $_SESSION['username']          = $team['username'];
    $_SESSION['competition_id']    = (int) $team['competition_id'];
    $_SESSION['status_registrasi'] = $team['status_registrasi'];

    jsonResponse(true, [
        'team_id'           => (int) $team['id'],
        'nama_tim'          => $team['nama_tim'],
        'username'          => $team['username'],
        'competition_id'    => (int) $team['competition_id'],
        'status_registrasi' => $team['status_registrasi'],
    ], 'Login berhasil');
}

/**
 * POST /api/auth/logout
 * Menghapus sesi login aktif.
 */
function handleLogout(): void
{
    requireLogin();

    $_SESSION = [];
    session_destroy();

    jsonResponse(true, null, 'Logout berhasil');
}

// ---------------------------------------------------------
// Google OAuth (belum aktif)
// TODO: Google OAuth — aktifkan setelah credentials Google Cloud Console tersedia
//
// GET /api/auth/google
//   Redirect browser ke halaman consent Google OAuth.
//
// GET /api/auth/google/callback
//   Terima ?code=...&state=... dari Google, tukar dengan access token,
//   simpan google_email hasil verifikasi ke $_SESSION sementara
//   (google_email, google_verified = true), lalu redirect ke
//   {APP_URL}/register.html. Belum membuat akun tim di tahap ini.
// ---------------------------------------------------------

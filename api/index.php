<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/response.php';
require_once __DIR__ . '/includes/middleware.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

// ---------------------------------------------------------
// CORS
// FE (Live Server, mis. http://127.0.0.1:5500) dan BE
// (php -S localhost:8080) berjalan di port berbeda saat
// development lokal, jadi header CORS wajib di-set manual.
// ---------------------------------------------------------
$allowedOrigin = $_SERVER['HTTP_ORIGIN'] ?? ($_ENV['APP_URL'] ?? '*');

header("Access-Control-Allow-Origin: {$allowedOrigin}");
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

// Preflight request berhenti di sini, tidak perlu masuk ke routing.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ---------------------------------------------------------
// Session (dipakai auth login/logout/me dan endpoint butuh login)
// ---------------------------------------------------------
session_name($_ENV['SESSION_NAME'] ?? 'dateraquest_session');
session_set_cookie_params(['samesite' => 'Lax']);
session_start();

// ---------------------------------------------------------
// Routing sederhana berdasarkan method + path
// ---------------------------------------------------------
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

// Buang prefix "/api" supaya route bisa didefinisikan tanpa prefix,
// baik saat api/ jadi root server (php -S) maupun saat di-mount di /api.
$path = preg_replace('#^/api#', '', $path);
$path = '/' . ltrim($path, '/');
$path = rtrim($path, '/') ?: '/';

$routes = [
    'GET /ping'             => [__DIR__ . '/routes/ping.php', 'handlePing'],
    'POST /auth/register'   => [__DIR__ . '/routes/auth.php', 'handleRegister'],
    'POST /auth/login'      => [__DIR__ . '/routes/auth.php', 'handleLogin'],
    'POST /auth/logout'     => [__DIR__ . '/routes/auth.php', 'handleLogout'],
    'GET /me'               => [__DIR__ . '/routes/team.php', 'handleMe'],
    'POST /payment/upload'  => [__DIR__ . '/routes/team.php', 'handlePaymentUpload'],
    'GET /competitions'     => [__DIR__ . '/routes/competition.php', 'handleListCompetitions'],
];

$routeKey = "{$method} {$path}";

if (!array_key_exists($routeKey, $routes)) {
    jsonResponse(false, null, 'Route tidak ditemukan', 404);
}

[$routeFile, $routeHandler] = $routes[$routeKey];

try {
    require_once $routeFile;
    $routeHandler();
} catch (Throwable $e) {
    jsonResponse(false, null, 'Terjadi kesalahan pada server', 500);
}

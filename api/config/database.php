<?php

declare(strict_types=1);

use Dotenv\Dotenv;

require_once __DIR__ . '/../vendor/autoload.php';

if (!isset($_ENV['DB_HOST'])) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->safeLoad();
}

/**
 * Membuat koneksi PDO ke MySQL menggunakan kredensial dari .env.
 */
function getDbConnection(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = $_ENV['DB_HOST'] ?? getenv('DB_HOST');
    $name = $_ENV['DB_NAME'] ?? getenv('DB_NAME');
    $user = $_ENV['DB_USER'] ?? getenv('DB_USER');
    $pass = $_ENV['DB_PASS'] ?? getenv('DB_PASS');

    if (!$host || !$name || !$user) {
        throw new RuntimeException(
            'Konfigurasi database tidak lengkap. Pastikan DB_HOST, DB_NAME, dan DB_USER sudah diset di file .env'
        );
    }

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $name);

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, $user, $pass ?: '', $options);
    } catch (PDOException $e) {
        throw new RuntimeException(
            'Gagal terhubung ke database "' . $name . '" di host "' . $host . '": ' . $e->getMessage(),
            (int) $e->getCode(),
            $e
        );
    }

    return $pdo;
}

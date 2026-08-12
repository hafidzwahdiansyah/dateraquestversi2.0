<?php

declare(strict_types=1);

/**
 * Mengirim response JSON dengan struktur konsisten dan menghentikan eksekusi.
 *
 * @param mixed $data
 */
function jsonResponse(bool $success, $data, string $message, int $statusCode = 200): never
{
    http_response_code($statusCode);
    echo json_encode([
        'success' => $success,
        'data'    => $data,
        'message' => $message,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Mem-parsing body request JSON menjadi array asosiatif.
 * Mengembalikan array kosong kalau body kosong/tidak valid JSON.
 */
function getJsonBody(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode((string) $raw, true);

    return is_array($data) ? $data : [];
}

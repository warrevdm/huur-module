<?php

declare(strict_types=1);

define('AAB_ALLOW_BIKE_IMAGE_GENERATION', true);
require_once __DIR__ . '/../app/bootstrap.php';
require_auth();

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');

if ((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Methode niet toegestaan.']);
    exit;
}

try {
    verify_csrf();

    $id = (int) ($_POST['id'] ?? 0);
    if ($id < 1) {
        throw new RuntimeException('Ongeldige fiets.');
    }

    $bike = find_bike($id);
    if (!$bike || empty($bike['photo_stored_name'])) {
        throw new RuntimeException('Geen fietsafbeelding gevonden.');
    }

    $variant = bike_generate_web_variant($bike, 240);
    if ($variant === null || !is_file($variant['cache_path'])) {
        throw new RuntimeException('Thumbnail kon niet worden gemaakt. Controleer GD, geheugen en schrijfrechten.');
    }

    echo json_encode([
        'ok' => true,
        'id' => $id,
        'url' => $variant['url'],
        'bytes' => (int) (@filesize($variant['cache_path']) ?: 0),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'error' => $e instanceof RuntimeException ? $e->getMessage() : 'Thumbnail kon niet worden gemaakt.',
    ], JSON_UNESCAPED_UNICODE);
}

<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/bike_rotate.php';
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
    $direction = (string) ($_POST['direction'] ?? '');
    if ($id < 1) {
        throw new RuntimeException('Ongeldige fiets.');
    }

    $bike = find_bike($id);
    if (!$bike) {
        throw new RuntimeException('Fiets niet gevonden.');
    }

    bike_rotate_photo($bike, $direction);
    $freshBike = find_bike($id);

    echo json_encode([
        'ok' => true,
        'id' => $id,
        'direction' => $direction,
        'url' => $freshBike ? bike_photo_src($freshBike, 800) : '',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'error' => $e instanceof RuntimeException ? $e->getMessage() : 'De foto kon niet worden gedraaid.',
    ], JSON_UNESCAPED_UNICODE);
}

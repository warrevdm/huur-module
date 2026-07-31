<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_auth();

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');

$start = parse_datetime((string) ($_GET['start_date'] ?? ''), (string) ($_GET['start_time'] ?? ''));
$end = parse_datetime((string) ($_GET['end_date'] ?? ''), (string) ($_GET['end_time'] ?? ''));

if (!$start || !$end || $end <= $start) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Ongeldige huurperiode.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$availability = bike_availability(
    $start->format('Y-m-d H:i:s'),
    $end->format('Y-m-d H:i:s')
);

$items = [];
foreach (all_bikes(true) as $bike) {
    $state = $availability[(int) $bike['id']] ?? ['available' => false, 'reason' => 'Onbekend', 'status' => $bike['status']];
    $items[] = [
        'id' => (int) $bike['id'],
        'available' => (bool) $state['available'],
        'reason' => $state['reason'],
        'status' => (string) $state['status'],
        'label' => (string) $bike['code'] . ' — ' . (string) $bike['name'] . ' (' . (string) $bike['category'] . ')',
    ];
}

echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

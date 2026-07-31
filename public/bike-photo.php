<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_auth();

$id = (int) ($_GET['id'] ?? 0);
$bike = find_bike($id);

if (!$bike || empty($bike['photo_stored_name']) || empty($bike['photo_mime_type'])) {
    http_response_code(404);
    exit('Afbeelding niet gevonden.');
}

$allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp'];
$mimeType = (string) $bike['photo_mime_type'];
if (!in_array($mimeType, $allowedMimeTypes, true)) {
    http_response_code(404);
    exit('Afbeelding niet gevonden.');
}

$path = ROOT_PATH . '/storage/private/bikes/' . basename((string) $bike['photo_stored_name']);
if (!is_file($path)) {
    http_response_code(404);
    exit('Afbeelding niet gevonden.');
}

$etag = '"' . hash_file('sha256', $path) . '"';
if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . rawurlencode((string) ($bike['photo_original_name'] ?: 'fietsafbeelding')) . '"');
header('Cache-Control: private, max-age=3600');
header('ETag: ' . $etag);
readfile($path);

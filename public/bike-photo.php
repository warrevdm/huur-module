<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_auth();

$id = (int) ($_GET['id'] ?? 0);
$bike = find_bike($id);
if (!$bike) {
    http_response_code(404);
    exit('Afbeelding niet gevonden.');
}

$size = bike_image_normalize_size((int) ($_GET['size'] ?? 800));
$variant = bike_ensure_web_variant($bike, $size);
if ($variant !== null && is_file($variant['cache_path'])) {
    header('Location: ' . $variant['url'], true, 302);
    header('Cache-Control: private, max-age=300');
    exit;
}

$storedName = basename((string) ($bike['photo_stored_name'] ?? ''));
$mimeType = (string) ($bike['photo_mime_type'] ?? '');
if ($storedName === '' || !in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    http_response_code(404);
    exit('Afbeelding niet gevonden.');
}

$path = ROOT_PATH . '/storage/private/bikes/' . $storedName;
if (!is_file($path)) {
    http_response_code(404);
    exit('Afbeelding niet gevonden.');
}

$mtime = (int) (@filemtime($path) ?: 0);
$bytes = (int) (@filesize($path) ?: 0);
$etag = '"' . hash('sha256', $storedName . '|' . $mtime . '|' . $bytes) . '"';
if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}

header('Content-Type: ' . $mimeType);
if ($bytes > 0) {
    header('Content-Length: ' . $bytes);
}
header('Content-Disposition: inline');
header('Cache-Control: private, max-age=86400');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');
header('X-Bike-Image-Mode: original-fallback');
readfile($path);

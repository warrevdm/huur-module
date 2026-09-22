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

$storedName = basename((string) ($bike['photo_stored_name'] ?? ''));
if ($storedName === '') {
    http_response_code(404);
    exit('Afbeelding niet gevonden.');
}

$path = ROOT_PATH . '/storage/private/bikes/' . $storedName;
if (!is_file($path) || !is_readable($path)) {
    http_response_code(404);
    exit('Afbeelding niet gevonden.');
}

$allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp'];
$mimeType = '';

if (class_exists('finfo')) {
    try {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->file($path);
        if (is_string($detected) && in_array($detected, $allowedMimeTypes, true)) {
            $mimeType = $detected;
        }
    } catch (Throwable) {
        // Fall through to the next safe detector.
    }
}

if ($mimeType === '') {
    $imageInfo = @getimagesize($path);
    $detected = is_array($imageInfo) ? (string) ($imageInfo['mime'] ?? '') : '';
    if (in_array($detected, $allowedMimeTypes, true)) {
        $mimeType = $detected;
    }
}

if ($mimeType === '') {
    $storedMime = (string) ($bike['photo_mime_type'] ?? '');
    if (in_array($storedMime, $allowedMimeTypes, true)) {
        $mimeType = $storedMime;
    }
}

if ($mimeType === '') {
    http_response_code(415);
    exit('Afbeeldingsformaat wordt niet ondersteund.');
}

$mtime = (int) (@filemtime($path) ?: 0);
$bytes = (int) (@filesize($path) ?: 0);
$etag = '"' . hash('sha256', $storedName . '|' . $mtime . '|' . $bytes) . '"';

if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    header('Cache-Control: private, max-age=86400');
    header('ETag: ' . $etag);
    exit;
}

header('Content-Type: ' . $mimeType);
if ($bytes > 0) {
    header('Content-Length: ' . $bytes);
}
header('Content-Disposition: inline; filename="' . rawurlencode($storedName) . '"');
header('Cache-Control: private, max-age=86400');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');
header('X-Bike-Image-Mode: original');
readfile($path);

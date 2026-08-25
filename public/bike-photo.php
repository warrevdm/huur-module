<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_auth();

function create_bike_web_variant(string $sourcePath, string $sourceMime, string $destinationPath, int $maxDimension, int $quality): bool
{
    if (!extension_loaded('gd')
        || !function_exists('imagecreatefromstring')
        || !function_exists('imagecreatetruecolor')
        || !function_exists('imagecopyresampled')
        || !function_exists('imagewebp')) {
        return false;
    }

    $contents = @file_get_contents($sourcePath);
    if ($contents === false) {
        return false;
    }

    $image = @imagecreatefromstring($contents);
    if ($image === false) {
        return false;
    }

    if ($sourceMime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($sourcePath);
        $orientation = (int) ($exif['Orientation'] ?? 1);
        $rotation = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($rotation !== 0 && function_exists('imagerotate')) {
            $rotated = @imagerotate($image, $rotation, 0);
            if ($rotated !== false) {
                imagedestroy($image);
                $image = $rotated;
            }
        }
    }

    $width = imagesx($image);
    $height = imagesy($image);
    if ($width < 1 || $height < 1) {
        imagedestroy($image);
        return false;
    }

    $scale = min(1, $maxDimension / max($width, $height));
    $targetWidth = max(1, (int) round($width * $scale));
    $targetHeight = max(1, (int) round($height * $scale));

    $target = imagecreatetruecolor($targetWidth, $targetHeight);
    if ($target === false) {
        imagedestroy($image);
        return false;
    }

    imagealphablending($target, false);
    imagesavealpha($target, true);
    $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
    imagefill($target, 0, 0, $transparent);
    imagealphablending($target, true);

    $resampled = imagecopyresampled(
        $target,
        $image,
        0,
        0,
        0,
        0,
        $targetWidth,
        $targetHeight,
        $width,
        $height
    );

    $written = $resampled && @imagewebp($target, $destinationPath, $quality);

    imagedestroy($target);
    imagedestroy($image);

    if ($written) {
        @chmod($destinationPath, 0640);
    }

    return $written;
}

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

$storedName = basename((string) $bike['photo_stored_name']);
$path = ROOT_PATH . '/storage/private/bikes/' . $storedName;
if (!is_file($path)) {
    http_response_code(404);
    exit('Afbeelding niet gevonden.');
}

$allowedSizes = [320, 480, 800, 1200, 1600];
$requestedSize = (int) ($_GET['size'] ?? 1200);
if (!in_array($requestedSize, $allowedSizes, true)) {
    $requestedSize = 1200;
}

$servedPath = $path;
$servedMime = $mimeType;
$imageInfo = @getimagesize($path);
$sourceWidth = (int) ($imageInfo[0] ?? 0);
$sourceHeight = (int) ($imageInfo[1] ?? 0);

if ($sourceWidth > 0 && $sourceHeight > 0) {
    $alreadyEfficient = $mimeType === 'image/webp' && max($sourceWidth, $sourceHeight) <= $requestedSize;

    if (!$alreadyEfficient) {
        $cacheDir = ROOT_PATH . '/storage/private/bikes/cache';
        if ((is_dir($cacheDir) || @mkdir($cacheDir, 0770, true)) && is_dir($cacheDir)) {
            $cacheBase = pathinfo($storedName, PATHINFO_FILENAME);
            $sourceVersion = (string) (@filemtime($path) ?: 0) . '-' . (string) (@filesize($path) ?: 0);
            $cachePath = $cacheDir . '/' . $cacheBase . '-' . $requestedSize . '-' . hash('sha256', $sourceVersion) . '.webp';

            if (is_file($cachePath) || create_bike_web_variant(
                $path,
                $mimeType,
                $cachePath,
                $requestedSize,
                $requestedSize <= 800 ? 78 : 82
            )) {
                $servedPath = $cachePath;
                $servedMime = 'image/webp';
            }
        }
    }
}

$servedMtime = (int) (@filemtime($servedPath) ?: 0);
$servedSize = (int) (@filesize($servedPath) ?: 0);
$etag = '"' . hash('sha256', basename($servedPath) . '|' . $servedMtime . '|' . $servedSize) . '"';

if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}

header('Content-Type: ' . $servedMime);
if ($servedSize > 0) {
    header('Content-Length: ' . $servedSize);
}
header('Content-Disposition: inline');
header('Cache-Control: private, max-age=604800');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');
readfile($servedPath);

<?php

declare(strict_types=1);

function bike_image_allowed_sizes(): array
{
    return [240, 320, 480, 800, 1200, 1600];
}

function bike_image_normalize_size(int $size): int
{
    return in_array($size, bike_image_allowed_sizes(), true) ? $size : 800;
}

function bike_image_variant_info(array $bike, int $size): ?array
{
    $storedName = basename((string) ($bike['photo_stored_name'] ?? ''));
    if ($storedName === '') {
        return null;
    }

    $sourcePath = ROOT_PATH . '/storage/private/bikes/' . $storedName;
    if (!is_file($sourcePath)) {
        return null;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string) $finfo->file($sourcePath);
    if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        return null;
    }

    $size = bike_image_normalize_size($size);
    $mtime = (int) (@filemtime($sourcePath) ?: 0);
    $bytes = (int) (@filesize($sourcePath) ?: 0);
    $base = pathinfo($storedName, PATHINFO_FILENAME);
    $version = substr(hash('sha256', $storedName . '|' . $mtime . '|' . $bytes), 0, 16);
    $filename = $base . '-' . $size . '-' . $version . '.webp';
    $cacheDir = ROOT_PATH . '/public/assets/bike-cache';
    $cachePath = $cacheDir . '/' . $filename;
    $appUrl = rtrim((string) env('APP_URL', ''), '/');
    $relativeUrl = 'assets/bike-cache/' . rawurlencode($filename);

    return [
        'size' => $size,
        'mime_type' => $mimeType,
        'source_path' => $sourcePath,
        'cache_dir' => $cacheDir,
        'cache_path' => $cachePath,
        'filename' => $filename,
        'url' => $appUrl !== '' ? $appUrl . '/' . $relativeUrl : $relativeUrl,
    ];
}

function bike_image_memory_limit_bytes(): int
{
    $value = trim((string) ini_get('memory_limit'));
    if ($value === '' || $value === '-1') {
        return PHP_INT_MAX;
    }

    $unit = strtolower(substr($value, -1));
    $number = (float) $value;
    return match ($unit) {
        'g' => (int) ($number * 1024 * 1024 * 1024),
        'm' => (int) ($number * 1024 * 1024),
        'k' => (int) ($number * 1024),
        default => (int) $number,
    };
}

function bike_generate_web_variant(array $bike, int $size): ?array
{
    $variant = bike_image_variant_info($bike, $size);
    if ($variant === null) {
        return null;
    }

    if (is_file($variant['cache_path']) && (int) @filesize($variant['cache_path']) > 0) {
        return $variant;
    }

    if (!is_dir($variant['cache_dir'])
        && !@mkdir($variant['cache_dir'], 0775, true)
        && !is_dir($variant['cache_dir'])) {
        return null;
    }

    if (!extension_loaded('gd')
        || !function_exists('imagecreatefromstring')
        || !function_exists('imagecreatetruecolor')
        || !function_exists('imagecopyresampled')
        || !function_exists('imagewebp')) {
        return null;
    }

    $imageInfo = @getimagesize($variant['source_path']);
    $sourceWidth = (int) ($imageInfo[0] ?? 0);
    $sourceHeight = (int) ($imageInfo[1] ?? 0);
    if ($sourceWidth < 1 || $sourceHeight < 1) {
        return null;
    }

    // Vermijd een fatale memory-limit op shared hosting bij uitzonderlijk grote originelen.
    $estimatedBytes = ($sourceWidth * $sourceHeight * 5) + (16 * 1024 * 1024);
    $memoryLimit = bike_image_memory_limit_bytes();
    $memoryUsed = (int) memory_get_usage(true);
    if ($memoryLimit !== PHP_INT_MAX && ($memoryUsed + $estimatedBytes) > ($memoryLimit * 0.85)) {
        return null;
    }

    $contents = @file_get_contents($variant['source_path']);
    if ($contents === false) {
        return null;
    }

    $source = @imagecreatefromstring($contents);
    unset($contents);
    if ($source === false) {
        return null;
    }

    if ($variant['mime_type'] === 'image/jpeg' && function_exists('exif_read_data') && function_exists('imagerotate')) {
        $exif = @exif_read_data($variant['source_path']);
        $orientation = (int) ($exif['Orientation'] ?? 1);
        $rotation = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
        if ($rotation !== 0) {
            $rotated = @imagerotate($source, $rotation, 0);
            if ($rotated !== false) {
                imagedestroy($source);
                $source = $rotated;
                $sourceWidth = imagesx($source);
                $sourceHeight = imagesy($source);
            }
        }
    }

    $scale = min(1, $variant['size'] / max($sourceWidth, $sourceHeight));
    $targetWidth = max(1, (int) round($sourceWidth * $scale));
    $targetHeight = max(1, (int) round($sourceHeight * $scale));
    $target = imagecreatetruecolor($targetWidth, $targetHeight);
    if ($target === false) {
        imagedestroy($source);
        return null;
    }

    imagealphablending($target, false);
    imagesavealpha($target, true);
    $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
    imagefill($target, 0, 0, $transparent);
    imagealphablending($target, true);

    $resampled = imagecopyresampled(
        $target,
        $source,
        0,
        0,
        0,
        0,
        $targetWidth,
        $targetHeight,
        $sourceWidth,
        $sourceHeight
    );

    $quality = $variant['size'] <= 240 ? 64 : ($variant['size'] <= 800 ? 76 : 82);
    $tmpPath = $variant['cache_path'] . '.tmp-' . bin2hex(random_bytes(4));
    $written = $resampled && @imagewebp($target, $tmpPath, $quality);

    imagedestroy($target);
    imagedestroy($source);

    if (!$written || !is_file($tmpPath) || (int) @filesize($tmpPath) < 1) {
        @unlink($tmpPath);
        return null;
    }

    @chmod($tmpPath, 0644);
    if (!@rename($tmpPath, $variant['cache_path'])) {
        @unlink($tmpPath);
        return is_file($variant['cache_path']) ? $variant : null;
    }
    @chmod($variant['cache_path'], 0644);

    return $variant;
}

function bike_ensure_web_variant(array $bike, int $size): ?array
{
    $variant = bike_image_variant_info($bike, $size);
    if ($variant !== null && is_file($variant['cache_path']) && (int) @filesize($variant['cache_path']) > 0) {
        return $variant;
    }

    // Alleen de dedicated thumbnail-endpoint mag on-demand genereren.
    if (defined('AAB_ALLOW_BIKE_IMAGE_GENERATION') && AAB_ALLOW_BIKE_IMAGE_GENERATION === true) {
        return bike_generate_web_variant($bike, $size);
    }

    return null;
}

function bike_pregenerate_web_variants(array $bike, array $sizes = [240, 800]): void
{
    // Bij één nieuwe upload zijn twee sequentiële varianten veilig; dit is geen bulkactie.
    foreach ($sizes as $size) {
        bike_generate_web_variant($bike, (int) $size);
    }
}

function bike_photo_src(array $bike, int $size = 240): string
{
    $size = bike_image_normalize_size($size);
    $variant = bike_image_variant_info($bike, $size);
    if ($variant !== null && is_file($variant['cache_path']) && (int) @filesize($variant['cache_path']) > 0) {
        return (string) $variant['url'];
    }

    $id = (int) ($bike['id'] ?? 0);
    $version = rawurlencode((string) ($bike['updated_at'] ?? ''));
    $appUrl = rtrim((string) env('APP_URL', ''), '/');
    $path = 'bike-photo.php?id=' . $id . '&size=' . $size . '&v=' . $version;

    return $appUrl !== '' ? $appUrl . '/' . $path : $path;
}

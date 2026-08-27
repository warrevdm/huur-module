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

function bike_image_public_web_path(string $filename): string
{
    $documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $publicRoot = realpath(ROOT_PATH . '/public');

    if ($documentRoot !== false && $publicRoot !== false) {
        $normalize = static fn (string $path): string => rtrim(str_replace('\\', '/', $path), '/');
        if ($normalize($documentRoot) === $normalize($publicRoot)) {
            return 'assets/bike-cache/' . rawurlencode($filename);
        }
    }

    return 'public/assets/bike-cache/' . rawurlencode($filename);
}

function bike_image_public_url(string $filename): string
{
    $path = bike_image_public_web_path($filename);
    $appUrl = rtrim((string) env('APP_URL', ''), '/');

    return $appUrl !== '' ? $appUrl . '/' . $path : $path;
}

function bike_image_variant_info(array $bike, int $size): ?array
{
    $storedName = basename((string) ($bike['photo_stored_name'] ?? ''));
    $mimeType = (string) ($bike['photo_mime_type'] ?? '');
    if ($storedName === '' || !in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        return null;
    }

    $sourcePath = ROOT_PATH . '/storage/private/bikes/' . $storedName;
    if (!is_file($sourcePath)) {
        return null;
    }

    $size = bike_image_normalize_size($size);
    $mtime = (int) (@filemtime($sourcePath) ?: 0);
    $bytes = (int) (@filesize($sourcePath) ?: 0);
    $cacheBase = pathinfo($storedName, PATHINFO_FILENAME);
    $version = substr(hash('sha256', $mtime . '|' . $bytes), 0, 16);
    $filename = $cacheBase . '-' . $size . '-' . $version . '.webp';

    return [
        'size' => $size,
        'mime_type' => $mimeType,
        'source_path' => $sourcePath,
        'cache_dir' => ROOT_PATH . '/public/assets/bike-cache',
        'cache_path' => ROOT_PATH . '/public/assets/bike-cache/' . $filename,
        'filename' => $filename,
        'url' => bike_image_public_url($filename),
    ];
}

function bike_create_webp_with_gd(string $sourcePath, string $sourceMime, string $destinationPath, int $maxDimension, int $quality): bool
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

    return $written;
}

function bike_create_webp_with_imagick(string $sourcePath, string $destinationPath, int $maxDimension, int $quality): bool
{
    if (!class_exists('Imagick')) {
        return false;
    }

    try {
        $image = new Imagick($sourcePath);
        if (method_exists($image, 'autoOrientImage')) {
            $image->autoOrientImage();
        }
        $image->setImageFormat('webp');
        $image->setImageCompressionQuality($quality);
        $image->thumbnailImage($maxDimension, $maxDimension, true, true);
        $image->stripImage();
        $written = $image->writeImage($destinationPath);
        $image->clear();
        $image->destroy();
        return (bool) $written;
    } catch (Throwable) {
        return false;
    }
}

function bike_ensure_web_variant(array $bike, int $size): ?array
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

    $imageInfo = @getimagesize($variant['source_path']);
    $sourceWidth = (int) ($imageInfo[0] ?? 0);
    $sourceHeight = (int) ($imageInfo[1] ?? 0);
    if ($sourceWidth < 1 || $sourceHeight < 1) {
        return null;
    }

    $quality = $variant['size'] <= 240 ? 62 : ($variant['size'] <= 480 ? 68 : ($variant['size'] <= 800 ? 76 : 82));
    $tmpPath = $variant['cache_path'] . '.tmp-' . bin2hex(random_bytes(4));

    $written = false;
    if ($variant['mime_type'] === 'image/webp' && max($sourceWidth, $sourceHeight) <= $variant['size']) {
        $written = @copy($variant['source_path'], $tmpPath);
    } else {
        $written = bike_create_webp_with_gd(
            $variant['source_path'],
            $variant['mime_type'],
            $tmpPath,
            $variant['size'],
            $quality
        );

        if (!$written) {
            $written = bike_create_webp_with_imagick(
                $variant['source_path'],
                $tmpPath,
                $variant['size'],
                $quality
            );
        }
    }

    if (!$written || !is_file($tmpPath) || (int) @filesize($tmpPath) < 1) {
        @unlink($tmpPath);
        return null;
    }

    @chmod($tmpPath, 0644);
    if (!@rename($tmpPath, $variant['cache_path'])) {
        if (!is_file($variant['cache_path'])) {
            @copy($tmpPath, $variant['cache_path']);
        }
        @unlink($tmpPath);
    }
    @chmod($variant['cache_path'], 0644);

    return is_file($variant['cache_path']) ? $variant : null;
}

function bike_pregenerate_web_variants(array $bike, array $sizes = [240, 800]): void
{
    foreach ($sizes as $size) {
        bike_ensure_web_variant($bike, (int) $size);
    }
}

function bike_photo_src(array $bike, int $size = 240): string
{
    $size = bike_image_normalize_size($size);
    $variant = bike_image_variant_info($bike, $size);
    if ($variant !== null && is_file($variant['cache_path'])) {
        return $variant['url'];
    }

    $id = (int) ($bike['id'] ?? 0);
    $version = rawurlencode((string) ($bike['updated_at'] ?? ''));
    return 'bike-photo.php?id=' . $id . '&size=' . $size . '&v=' . $version;
}

<?php

declare(strict_types=1);

function bike_rotation_cache_clear(array $bike): void
{
    $storedName = basename((string) ($bike['photo_stored_name'] ?? ''));
    if ($storedName === '') {
        return;
    }

    $base = pathinfo($storedName, PATHINFO_FILENAME);
    $cacheDir = ROOT_PATH . '/public/assets/bike-cache';
    if (!is_dir($cacheDir)) {
        return;
    }

    foreach (glob($cacheDir . '/' . $base . '-*.webp') ?: [] as $cacheFile) {
        if (is_file($cacheFile)) {
            @unlink($cacheFile);
        }
    }
}

function bike_rotate_with_imagick(string $path, string $mimeType, string $direction): bool
{
    if (!class_exists('Imagick')) {
        return false;
    }

    $tmpPath = $path . '.rotate-' . bin2hex(random_bytes(4));

    try {
        $image = new Imagick($path);
        if (method_exists($image, 'autoOrientImage')) {
            $image->autoOrientImage();
        }

        $degrees = $direction === 'left' ? -90 : 90;
        $pixel = new ImagickPixel('transparent');
        $image->rotateImage($pixel, $degrees);

        if (defined('Imagick::ORIENTATION_TOPLEFT')) {
            $image->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
        }

        $format = match ($mimeType) {
            'image/jpeg' => 'jpeg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => '',
        };
        if ($format === '') {
            throw new RuntimeException('Niet-ondersteund bestandstype.');
        }

        $image->setImageFormat($format);
        if ($mimeType === 'image/jpeg') {
            $image->setImageCompressionQuality(92);
        } elseif ($mimeType === 'image/webp') {
            $image->setImageCompressionQuality(88);
        }
        $image->stripImage();

        $written = (bool) $image->writeImage($tmpPath);
        $image->clear();
        $image->destroy();

        if (!$written || !is_file($tmpPath) || (int) @filesize($tmpPath) < 1) {
            @unlink($tmpPath);
            return false;
        }

        @chmod($tmpPath, 0660);
        if (!@rename($tmpPath, $path)) {
            @unlink($tmpPath);
            return false;
        }

        return true;
    } catch (Throwable) {
        @unlink($tmpPath);
        return false;
    }
}

function bike_rotate_with_gd(string $path, string $mimeType, string $direction): bool
{
    if (!extension_loaded('gd') || !function_exists('imagecreatefromstring') || !function_exists('imagerotate')) {
        return false;
    }

    $contents = @file_get_contents($path);
    if ($contents === false) {
        return false;
    }

    $source = @imagecreatefromstring($contents);
    unset($contents);
    if ($source === false) {
        return false;
    }

    // Maak JPEG eerst fysiek recht volgens EXIF, zodat verdere rotaties voorspelbaar zijn.
    if ($mimeType === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($path);
        $orientation = (int) ($exif['Orientation'] ?? 1);
        $autoRotation = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
        if ($autoRotation !== 0) {
            $auto = @imagerotate($source, $autoRotation, 0);
            if ($auto !== false) {
                imagedestroy($source);
                $source = $auto;
            }
        }
    }

    $angle = $direction === 'left' ? 90 : -90;
    $background = imagecolorallocatealpha($source, 0, 0, 0, 127);
    $rotated = @imagerotate($source, $angle, $background);
    imagedestroy($source);
    if ($rotated === false) {
        return false;
    }

    imagealphablending($rotated, false);
    imagesavealpha($rotated, true);

    $tmpPath = $path . '.rotate-' . bin2hex(random_bytes(4));
    $written = match ($mimeType) {
        'image/jpeg' => function_exists('imagejpeg') && @imagejpeg($rotated, $tmpPath, 92),
        'image/png' => function_exists('imagepng') && @imagepng($rotated, $tmpPath, 6),
        'image/webp' => function_exists('imagewebp') && @imagewebp($rotated, $tmpPath, 88),
        default => false,
    };
    imagedestroy($rotated);

    if (!$written || !is_file($tmpPath) || (int) @filesize($tmpPath) < 1) {
        @unlink($tmpPath);
        return false;
    }

    @chmod($tmpPath, 0660);
    if (!@rename($tmpPath, $path)) {
        @unlink($tmpPath);
        return false;
    }

    return true;
}

function bike_rotate_photo(array $bike, string $direction): void
{
    if (!in_array($direction, ['left', 'right'], true)) {
        throw new RuntimeException('Ongeldige draairichting.');
    }

    $storedName = basename((string) ($bike['photo_stored_name'] ?? ''));
    if ($storedName === '') {
        throw new RuntimeException('Deze fiets heeft geen foto.');
    }

    $path = ROOT_PATH . '/storage/private/bikes/' . $storedName;
    if (!is_file($path)) {
        throw new RuntimeException('Het fotobestand werd niet gevonden.');
    }
    if (!is_writable($path)) {
        throw new RuntimeException('Het fotobestand is niet schrijfbaar.');
    }

    $mimeType = bike_image_detect_mime($path);
    if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        throw new RuntimeException('Alleen JPG, PNG en WebP kunnen worden gedraaid.');
    }

    bike_rotation_cache_clear($bike);

    $rotated = bike_rotate_with_imagick($path, $mimeType, $direction);
    if (!$rotated) {
        $rotated = bike_rotate_with_gd($path, $mimeType, $direction);
    }
    if (!$rotated) {
        throw new RuntimeException('De foto kon niet worden gedraaid. Controleer Imagick/GD en het beschikbare PHP-geheugen.');
    }

    clearstatcache(true, $path);
    bike_rotation_cache_clear($bike);

    $bikeId = (int) ($bike['id'] ?? 0);
    if ($bikeId > 0) {
        $stmt = db()->prepare('UPDATE bikes SET updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute([':id' => $bikeId]);
        audit('rotate_photo', 'bike', $bikeId, ['direction' => $direction]);

        $freshBike = find_bike($bikeId);
        if ($freshBike) {
            // Eén kleine thumbnail genereren is veilig en maakt het overzicht meteen snel.
            bike_generate_web_variant($freshBike, 240);
        }
    }
}

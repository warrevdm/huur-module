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
    return null;
}

function bike_ensure_web_variant(array $bike, int $size): ?array
{
    return null;
}

function bike_pregenerate_web_variants(array $bike, array $sizes = [240, 800]): void
{
    // Intentionally disabled on shared hosting. Image optimization must never block bike management.
}

function bike_photo_src(array $bike, int $size = 240): string
{
    $size = bike_image_normalize_size($size);
    $id = (int) ($bike['id'] ?? 0);
    $version = rawurlencode((string) ($bike['updated_at'] ?? ''));
    $appUrl = rtrim((string) env('APP_URL', ''), '/');
    $path = 'bike-photo.php?id=' . $id . '&size=' . $size . '&v=' . $version;

    return $appUrl !== '' ? $appUrl . '/' . $path : $path;
}

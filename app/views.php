<?php

declare(strict_types=1);

function render_header(string $title, bool $showNav = true): void
{
    $appName = env('APP_NAME', 'Aerts Action Bike Verhuur');
    $user = current_user();
    $flashes = take_flashes();
    $stylesVersion = is_file(ROOT_PATH . '/public/assets/styles.css') ? (string) filemtime(ROOT_PATH . '/public/assets/styles.css') : '1';
    $planningStatusVersion = is_file(ROOT_PATH . '/public/assets/planning-status.css') ? (string) filemtime(ROOT_PATH . '/public/assets/planning-status.css') : '1';
    $contractVersion = is_file(ROOT_PATH . '/public/assets/contract.css') ? (string) filemtime(ROOT_PATH . '/public/assets/contract.css') : '1';
    $brandingVersion = is_file(ROOT_PATH . '/public/assets/branding.css') ? (string) filemtime(ROOT_PATH . '/public/assets/branding.css') : '1';
    $eidBridgeStyleVersion = is_file(ROOT_PATH . '/public/assets/eid-bridge.css') ? (string) filemtime(ROOT_PATH . '/public/assets/eid-bridge.css') : '1';
    ?>
    <!doctype html>
    <html lang="nl-BE">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= e($title) ?> · <?= e($appName) ?></title>
        <link rel="icon" href="assets/aerts-action-bike-logo.svg" type="image/svg+xml">
        <link rel="stylesheet" href="assets/styles.css?v=<?= e($stylesVersion) ?>">
        <link rel="stylesheet" href="assets/planning-status.css?v=<?= e($planningStatusVersion) ?>">
        <link rel="stylesheet" href="assets/contract.css?v=<?= e($contractVersion) ?>">
        <link rel="stylesheet" href="assets/branding.css?v=<?= e($brandingVersion) ?>">
        <link rel="stylesheet" href="assets/eid-bridge.css?v=<?= e($eidBridgeStyleVersion) ?>">
    </head>
    <body>
    <?php if ($showNav && $user): ?>
        <header class="topbar">
            <a class="brand" href="planning.php" aria-label="<?= e($appName) ?>">
                <img src="assets/aerts-action-bike-logo.svg" alt="Aerts Action Bike">
                <span class="sr-only"><?= e($appName) ?></span>
            </a>
            <nav>
                <a href="planning.php">Planning</a>
                <a href="reservation-new.php">Nieuwe verhuur</a>
                <a href="bikes.php">Fietsen</a>
                <?php if (($user['role'] ?? '') === 'admin'): ?>
                    <a href="users.php">Gebruikers</a>
                <?php endif; ?>
            </nav>
            <form method="post" action="index.php?route=logout" class="logout-form">
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <span><?= e($user['name']) ?></span>
                <button class="button button-ghost" type="submit">Afmelden</button>
            </form>
        </header>
    <?php endif; ?>
    <main class="container <?= $showNav ? '' : 'container-narrow' ?>">
        <?php foreach ($flashes as $flash): ?>
            <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
        <?php endforeach; ?>
        <div class="page-heading"><h1><?= e($title) ?></h1></div>
    <?php
}

function render_footer(): void
{
    $appVersion = is_file(ROOT_PATH . '/public/assets/app.js') ? (string) filemtime(ROOT_PATH . '/public/assets/app.js') : '1';
    $eidBridgeVersion = is_file(ROOT_PATH . '/public/assets/eid-bridge.js') ? (string) filemtime(ROOT_PATH . '/public/assets/eid-bridge.js') : '1';
    $bikeOptimizeVersion = is_file(ROOT_PATH . '/public/assets/bike-optimize.js') ? (string) filemtime(ROOT_PATH . '/public/assets/bike-optimize.js') : '1';
    ?>
    </main>
    <script src="assets/app.js?v=<?= e($appVersion) ?>" defer></script>
    <script src="assets/eid-bridge.js?v=<?= e($eidBridgeVersion) ?>" defer></script>
    <script src="assets/bike-optimize.js?v=<?= e($bikeOptimizeVersion) ?>" defer></script>
    </body>
    </html>
    <?php
}

function status_label(string $status): string
{
    return match ($status) {
        'reserved' => 'Gereserveerd',
        'confirmed' => 'Bevestigd',
        'picked_up' => 'Afgehaald',
        'returned' => 'Teruggebracht',
        'cancelled' => 'Geannuleerd',
        default => ucfirst($status),
    };
}

function bike_status_label(string $status): string
{
    return match ($status) {
        'active' => 'Actief',
        'maintenance' => 'Onderhoud',
        'inactive' => 'Inactief',
        default => ucfirst($status),
    };
}

function bike_usage_type_label(string $usageType): string
{
    return match ($usageType) {
        'rental' => 'Huurfiets',
        'replacement' => 'Vervangfiets',
        'test' => 'Testfiets',
        'replacement_rental' => 'Vervang-/huurfiets',
        default => 'Huurfiets',
    };
}

function payment_method_label(string $method): string
{
    return match ($method) {
        'bancontact' => 'Bancontact',
        'cash' => 'Cash',
        default => ucfirst($method),
    };
}

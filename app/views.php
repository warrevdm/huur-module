<?php

declare(strict_types=1);

function render_header(string $title, bool $showNav = true): void
{
    $appName = env('APP_NAME', 'Aerts Action Bike Verhuur');
    $user = current_user();
    $flashes = take_flashes();
    ?>
    <!doctype html>
    <html lang="nl-BE">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= e($title) ?> · <?= e($appName) ?></title>
        <link rel="stylesheet" href="assets/styles.css">
        <link rel="stylesheet" href="assets/contract.css">
    </head>
    <body>
    <?php if ($showNav && $user): ?>
        <header class="topbar">
            <a class="brand" href="index.php?route=planning"><?= e($appName) ?></a>
            <nav>
                <a href="index.php?route=planning">Planning</a>
                <a href="index.php?route=reservation-new">Nieuwe verhuur</a>
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
        <div class="page-heading">
            <h1><?= e($title) ?></h1>
        </div>
    <?php
}

function render_footer(): void
{
    ?>
    </main>
    <script src="assets/app.js" defer></script>
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

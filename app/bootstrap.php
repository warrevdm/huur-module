<?php

declare(strict_types=1);

const ROOT_PATH = __DIR__ . '/..';

$autoload = ROOT_PATH . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/repositories.php';
require_once __DIR__ . '/views.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/contracts_v2.php';
require_once __DIR__ . '/reservation_status.php';

load_env(ROOT_PATH . '/.env');
date_default_timezone_set(env('APP_TIMEZONE', 'Europe/Brussels'));

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

session_name(env('SESSION_NAME', 'aab_huur_session'));
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; connect-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");

if (PHP_SAPI !== 'cli' && basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) === 'index.php') {
    $route = (string) ($_GET['route'] ?? '');
    $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if ($route === 'reservation-status' && $method === 'POST') {
        handle_reservation_status_request();
    }

    if ($method === 'GET') {
        $query = $_GET;
        unset($query['route']);
        $suffix = $query ? '?' . http_build_query($query) : '';

        if ($route === '' || $route === 'planning') {
            redirect('planning.php' . $suffix);
        }
        if ($route === 'reservation-new') {
            redirect('reservation-new.php' . $suffix);
        }
        if ($route === 'reservation-view') {
            redirect('reservation.php' . $suffix);
        }
        if ($route === 'bikes') {
            redirect('bikes.php' . $suffix);
        }
    }
}

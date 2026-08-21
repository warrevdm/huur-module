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
require_once __DIR__ . '/pricing.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/contracts_v2.php';
require_once __DIR__ . '/reservation_status.php';

load_env(ROOT_PATH . '/.env');
date_default_timezone_set(env('APP_TIMEZONE', 'Europe/Brussels'));

$appEnv = strtolower(trim((string) env('APP_ENV', 'production')));
$isProduction = $appEnv === 'production';
$appDebug = filter_var(env('APP_DEBUG', '0'), FILTER_VALIDATE_BOOLEAN);

error_reporting(E_ALL);
ini_set('log_errors', '1');

$logDir = ROOT_PATH . '/storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0770, true);
}
if (is_dir($logDir)) {
    ini_set('error_log', $logDir . '/php-error.log');
}

if ($isProduction || !$appDebug) {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
} else {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
}

if (PHP_SAPI !== 'cli' && $isProduction) {
    set_exception_handler(static function (Throwable $e): void {
        error_log(
            '[uncaught] ' . get_class($e) . ': ' . $e->getMessage()
            . ' in ' . $e->getFile() . ':' . $e->getLine()
            . "\n" . $e->getTraceAsString()
        );

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=UTF-8');
            header('Cache-Control: no-store, private');
        }

        echo '<!doctype html><html lang="nl-BE"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Technische fout · Aerts Action Bike</title></head>'
            . '<body><main style="max-width:680px;margin:60px auto;padding:24px;font-family:Arial,sans-serif">'
            . '<h1>Er ging iets mis</h1>'
            . '<p>De verhuurmodule kon deze actie niet afronden. Probeer opnieuw. '
            . 'Blijft het probleem bestaan, meld het bij de beheerder.</p>'
            . '</main></body></html>';
    });
}
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

header_remove('X-Powered-By');
header('X-Frame-Options: DENY');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; connect-src 'self' http://127.0.0.1:17895 http://localhost:17895; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");

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

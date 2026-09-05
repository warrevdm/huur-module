<?php

declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $relativePath = env('DB_PATH', 'storage/database.sqlite');
    $dbPath = str_starts_with((string) $relativePath, '/')
        ? (string) $relativePath
        : ROOT_PATH . '/' . $relativePath;

    $directory = dirname($dbPath);
    if (!is_dir($directory)) {
        mkdir($directory, 0770, true);
    }

    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');

    return $pdo;
}

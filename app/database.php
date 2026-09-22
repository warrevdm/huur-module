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

    ensure_reservation_kind_schema($pdo);

    return $pdo;
}


function ensure_reservation_kind_schema(PDO $pdo): void
{
    $tableExists = (bool) $pdo->query(
        "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'reservations' LIMIT 1"
    )->fetchColumn();

    if (!$tableExists) {
        return;
    }

    $columns = [];
    foreach ($pdo->query('PRAGMA table_info(reservations)')->fetchAll() as $column) {
        $columns[(string) $column['name']] = true;
    }

    if (!isset($columns['rental_kind'])) {
        $pdo->exec(
            "ALTER TABLE reservations
             ADD COLUMN rental_kind TEXT NOT NULL DEFAULT 'rental'
             CHECK(rental_kind IN ('rental', 'replacement'))"
        );
    }

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reservations_rental_kind ON reservations(rental_kind)');
    $pdo->exec(
        "UPDATE reservations
         SET rental_kind = 'replacement'
         WHERE rental_kind = 'rental'
           AND notes LIKE 'Snelle fietsregistratie via werkplaats.%'"
    );
}

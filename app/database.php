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

    ensure_user_role_schema($pdo);
    ensure_reservation_kind_schema($pdo);
    ensure_replacement_management_schema($pdo);

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


function ensure_user_role_schema(PDO $pdo): void
{
    $tableSql = $pdo->query(
        "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'users' LIMIT 1"
    )->fetchColumn();

    if (!$tableSql || str_contains((string) $tableSql, "'finance'")) {
        return;
    }

    $pdo->exec('PRAGMA foreign_keys = OFF');

    try {
        $pdo->beginTransaction();

        $pdo->exec(
            "CREATE TABLE users_role_migration (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT 'staff' CHECK(role IN ('admin', 'staff', 'finance')),
                active INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );

        $pdo->exec(
            "INSERT INTO users_role_migration (id, name, email, password_hash, role, active, created_at)
             SELECT id, name, email, password_hash, role, active, created_at
             FROM users"
        );

        $pdo->exec('DROP TABLE users');
        $pdo->exec('ALTER TABLE users_role_migration RENAME TO users');

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->exec('PRAGMA foreign_keys = ON');
        throw $e;
    }

    $pdo->exec('PRAGMA foreign_keys = ON');

    $violations = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
    if ($violations) {
        throw new RuntimeException('Gebruikersrolmigratie veroorzaakte een foreign-keyfout.');
    }
}


function ensure_replacement_management_schema(PDO $pdo): void
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

    $migrations = [
        'replacement_cost_note' => 'ALTER TABLE reservations ADD COLUMN replacement_cost_note TEXT',
        'cancelled_reason' => 'ALTER TABLE reservations ADD COLUMN cancelled_reason TEXT',
        'cancelled_by' => 'ALTER TABLE reservations ADD COLUMN cancelled_by INTEGER REFERENCES users(id)',
        'cancelled_at' => 'ALTER TABLE reservations ADD COLUMN cancelled_at TEXT',
    ];

    foreach ($migrations as $column => $sql) {
        if (!isset($columns[$column])) {
            $pdo->exec($sql);
        }
    }
}

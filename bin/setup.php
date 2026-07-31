<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$schema = file_get_contents(ROOT_PATH . '/database/schema.sql');
if ($schema === false) {
    fwrite(STDERR, "Schema niet gevonden.\n");
    exit(1);
}

db()->exec($schema);

$bikeColumns = [];
foreach (db()->query('PRAGMA table_info(bikes)')->fetchAll() as $column) {
    $bikeColumns[(string) $column['name']] = true;
}

$bikeMigrations = [
    'frame_number' => 'ALTER TABLE bikes ADD COLUMN frame_number TEXT',
    'photo_stored_name' => 'ALTER TABLE bikes ADD COLUMN photo_stored_name TEXT',
    'photo_original_name' => 'ALTER TABLE bikes ADD COLUMN photo_original_name TEXT',
    'photo_mime_type' => 'ALTER TABLE bikes ADD COLUMN photo_mime_type TEXT',
    'photo_size_bytes' => 'ALTER TABLE bikes ADD COLUMN photo_size_bytes INTEGER',
];

foreach ($bikeMigrations as $column => $sql) {
    if (!isset($bikeColumns[$column])) {
        db()->exec($sql);
        echo "Fietskolom toegevoegd: {$column}\n";
    }
}

db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_bikes_frame_number_unique ON bikes(frame_number) WHERE frame_number IS NOT NULL AND frame_number != ''");

$reservationColumns = [];
foreach (db()->query('PRAGMA table_info(reservations)')->fetchAll() as $column) {
    $reservationColumns[(string) $column['name']] = true;
}

$reservationMigrations = [
    'closed_by' => 'ALTER TABLE reservations ADD COLUMN closed_by INTEGER REFERENCES users(id)',
    'closed_at' => 'ALTER TABLE reservations ADD COLUMN closed_at TEXT',
];

foreach ($reservationMigrations as $column => $sql) {
    if (!isset($reservationColumns[$column])) {
        db()->exec($sql);
        echo "Reservatiekolom toegevoegd: {$column}\n";
    }
}

db()->exec('CREATE INDEX IF NOT EXISTS idx_reservations_closed_at ON reservations(closed_at)');

$bikePhotoDir = ROOT_PATH . '/storage/private/bikes';
if (!is_dir($bikePhotoDir) && !mkdir($bikePhotoDir, 0770, true) && !is_dir($bikePhotoDir)) {
    fwrite(STDERR, "Map voor fietsafbeeldingen kon niet worden aangemaakt.\n");
    exit(1);
}

$email = env('ADMIN_EMAIL', 'admin@example.com');
$password = env('ADMIN_PASSWORD', 'change-me-now');
$name = env('ADMIN_NAME', 'Beheerder');

$existing = find_user_by_email((string) $email);
if ($existing !== null) {
    echo "Admin bestaat al: {$existing['email']}\n";
} else {
    $existingAdmin = db()->query("SELECT * FROM users WHERE role = 'admin' AND active = 1 ORDER BY id LIMIT 1")->fetch();
    if ($existingAdmin) {
        echo "Admin bestaat al: {$existingAdmin['email']}\n";
    } else {
        $stmt = db()->prepare(
            "INSERT INTO users (name, email, password_hash, role, active)
             VALUES (:name, :email, :password_hash, 'admin', 1)"
        );
        $stmt->execute([
            ':name' => $name,
            ':email' => $email,
            ':password_hash' => password_hash((string) $password, PASSWORD_DEFAULT),
        ]);
        echo "Admin aangemaakt: {$email}\n";
    }
}

echo "Database klaar.\n";

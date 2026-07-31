<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$schema = file_get_contents(ROOT_PATH . '/database/schema.sql');
if ($schema === false) {
    fwrite(STDERR, "Schema niet gevonden.\n");
    exit(1);
}

db()->exec($schema);

$email = env('ADMIN_EMAIL', 'admin@example.com');
$password = env('ADMIN_PASSWORD', 'change-me-now');
$name = env('ADMIN_NAME', 'Beheerder');

$existing = find_user_by_email((string) $email);
if ($existing === null) {
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
} else {
    echo "Admin bestaat al: {$email}\n";
}

echo "Database klaar.\n";

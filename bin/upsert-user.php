<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$name = trim((string) ($argv[1] ?? ''));
$email = strtolower(trim((string) ($argv[2] ?? '')));
$role = trim((string) ($argv[3] ?? 'staff'));

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($role, ['admin', 'staff'], true)) {
    fwrite(STDERR, "Gebruik: php bin/upsert-user.php \"Naam\" e-mail@voorbeeld.be [staff|admin]\n");
    exit(1);
}

fwrite(STDOUT, "Wachtwoord voor {$email}: ");
$canHide = DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec');
if ($canHide) {
    @shell_exec('stty -echo');
}
$password = trim((string) fgets(STDIN));
if ($canHide) {
    @shell_exec('stty echo');
}
fwrite(STDOUT, PHP_EOL);

if (strlen($password) < 8) {
    fwrite(STDERR, "Het wachtwoord moet minstens 8 tekens bevatten.\n");
    exit(1);
}

$stmt = db()->prepare('SELECT id FROM users WHERE lower(email) = lower(:email) LIMIT 1');
$stmt->execute([':email' => $email]);
$userId = $stmt->fetchColumn();

if ($userId) {
    $stmt = db()->prepare(
        'UPDATE users
         SET name = :name, password_hash = :password_hash, role = :role, active = 1
         WHERE id = :id'
    );
    $stmt->execute([
        ':name' => $name,
        ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ':role' => $role,
        ':id' => (int) $userId,
    ]);
    fwrite(STDOUT, "Profiel bijgewerkt: {$email}\n");
} else {
    $stmt = db()->prepare(
        'INSERT INTO users (name, email, password_hash, role, active, created_at)
         VALUES (:name, :email, :password_hash, :role, 1, CURRENT_TIMESTAMP)'
    );
    $stmt->execute([
        ':name' => $name,
        ':email' => $email,
        ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ':role' => $role,
    ]);
    fwrite(STDOUT, "Profiel aangemaakt: {$email}\n");
}

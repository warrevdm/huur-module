<?php

declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $provided = $_POST['_token'] ?? '';
    if (!is_string($provided) || !hash_equals(csrf_token(), $provided)) {
        http_response_code(419);
        exit('Ongeldige of verlopen sessie. Vernieuw de pagina en probeer opnieuw.');
    }
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_auth(): void
{
    if (current_user() === null) {
        redirect('index.php?route=login');
    }
}

function is_admin(): bool
{
    return (current_user()['role'] ?? '') === 'admin';
}

function require_admin(): void
{
    require_auth();
    if (!is_admin()) {
        http_response_code(403);
        exit('Geen toegang.');
    }
}

function can_use_quick_replacement(): bool
{
    $user = current_user();
    if (!$user) {
        return false;
    }

    if (($user['role'] ?? '') === 'admin') {
        return true;
    }

    return strtolower(trim((string) ($user['email'] ?? ''))) === 'berten@aertsactionbike.be';
}

function require_quick_replacement(): void
{
    require_auth();
    if (!can_use_quick_replacement()) {
        http_response_code(403);
        exit('Geen toegang tot Snelle vervangfiets.');
    }
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => $user['role'],
    ];
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function login_rate_limit_config(): array
{
    return [
        'max_attempts' => max(3, min(20, (int) env('LOGIN_MAX_ATTEMPTS', '5'))),
        'window_minutes' => max(5, min(60, (int) env('LOGIN_WINDOW_MINUTES', '15'))),
    ];
}

function login_rate_limit_bucket(string $type, string $value): string
{
    return hash('sha256', $type . "\0" . strtolower(trim($value)));
}

function login_request_ip(): string
{
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    return $ip !== '' ? $ip : 'unknown';
}

function login_attempt_count(string $bucketType, string $bucketKey, string $cutoff): int
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE bucket_type = :bucket_type
           AND bucket_key = :bucket_key
           AND attempted_at >= :cutoff'
    );
    $stmt->execute([
        ':bucket_type' => $bucketType,
        ':bucket_key' => $bucketKey,
        ':cutoff' => $cutoff,
    ]);
    return (int) $stmt->fetchColumn();
}

function login_is_rate_limited(string $email): bool
{
    $config = login_rate_limit_config();
    $cutoff = gmdate('Y-m-d H:i:s', time() - ($config['window_minutes'] * 60));

    $ipCount = login_attempt_count('ip', login_rate_limit_bucket('ip', login_request_ip()), $cutoff);

    $accountCount = 0;
    $email = strtolower(trim($email));
    if ($email !== '') {
        $accountCount = login_attempt_count('account', login_rate_limit_bucket('account', $email), $cutoff);
    }

    return $accountCount >= $config['max_attempts']
        || $ipCount >= ($config['max_attempts'] * 3);
}

function record_login_failure(string $email): void
{
    $cleanup = db()->prepare('DELETE FROM login_attempts WHERE attempted_at < :cutoff');
    $cleanup->execute([':cutoff' => gmdate('Y-m-d H:i:s', time() - 86400)]);

    $stmt = db()->prepare(
        'INSERT INTO login_attempts (bucket_type, bucket_key, attempted_at)
         VALUES (:bucket_type, :bucket_key, CURRENT_TIMESTAMP)'
    );

    $stmt->execute([
        ':bucket_type' => 'ip',
        ':bucket_key' => login_rate_limit_bucket('ip', login_request_ip()),
    ]);

    $email = strtolower(trim($email));
    if ($email !== '') {
        $stmt->execute([
            ':bucket_type' => 'account',
            ':bucket_key' => login_rate_limit_bucket('account', $email),
        ]);
    }
}

function clear_login_failures(string $email): void
{
    $email = strtolower(trim($email));
    if ($email === '') return;

    $stmt = db()->prepare(
        "DELETE FROM login_attempts
         WHERE bucket_type = 'account' AND bucket_key = :bucket_key"
    );
    $stmt->execute([
        ':bucket_key' => login_rate_limit_bucket('account', $email),
    ]);
}

function redirect(string $location): never{
    header('Location: ' . $location);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

function parse_datetime(string $date, string $time): ?DateTimeImmutable
{
    $dateTime = DateTimeImmutable::createFromFormat('!Y-m-d H:i', trim($date) . ' ' . trim($time));
    $errors = DateTimeImmutable::getLastErrors();
    if ($dateTime === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        return null;
    }
    return $dateTime;
}

function upload_identity_document(array $file, int $customerId, ?string $retentionUntil): ?int
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Uploaden van het identiteitsdocument is mislukt.');
    }

    $maxBytes = ((int) env('ID_MAX_MB', '8')) * 1024 * 1024;
    $size = (int) ($file['size'] ?? 0);
    if ($size < 1 || $size > $maxBytes) {
        throw new RuntimeException('Het identiteitsdocument is te groot. Maximum: ' . env('ID_MAX_MB', '8') . ' MB.');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if (!is_uploaded_file($tmpName)) {
        throw new RuntimeException('Ongeldige upload.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmpName);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'application/pdf' => 'pdf',
    ];
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Enkel JPG, PNG en PDF zijn toegestaan.');
    }

    $storedName = bin2hex(random_bytes(24)) . '.' . $allowed[$mime];
    $uploadDir = ROOT_PATH . '/storage/private/ids';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0770, true);
    }
    $destination = $uploadDir . '/' . $storedName;
    if (!move_uploaded_file($tmpName, $destination)) {
        throw new RuntimeException('Het document kon niet veilig worden opgeslagen.');
    }
    @chmod($destination, 0640);

    $stmt = db()->prepare(
        'INSERT INTO identity_documents (customer_id, original_name, stored_name, mime_type, size_bytes, retention_until, created_at)
         VALUES (:customer_id, :original_name, :stored_name, :mime_type, :size_bytes, :retention_until, CURRENT_TIMESTAMP)'
    );
    $stmt->execute([
        ':customer_id' => $customerId,
        ':original_name' => basename((string) ($file['name'] ?? 'identiteitsdocument')),
        ':stored_name' => $storedName,
        ':mime_type' => $mime,
        ':size_bytes' => $size,
        ':retention_until' => $retentionUntil,
    ]);

    return (int) db()->lastInsertId();
}

function upload_bike_photo(array $file): ?array
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Uploaden van de fietsafbeelding is mislukt.');
    }

    $maxMb = max(1, (int) env('BIKE_IMAGE_MAX_MB', '8'));
    $maxBytes = $maxMb * 1024 * 1024;
    $size = (int) ($file['size'] ?? 0);
    if ($size < 1 || $size > $maxBytes) {
        throw new RuntimeException('De fietsafbeelding is te groot. Maximum: ' . $maxMb . ' MB.');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if (!is_uploaded_file($tmpName)) {
        throw new RuntimeException('Ongeldige afbeeldingsupload.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmpName);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Gebruik een JPG-, PNG- of WebP-afbeelding.');
    }

    $imageInfo = @getimagesize($tmpName);
    $width = (int) ($imageInfo[0] ?? 0);
    $height = (int) ($imageInfo[1] ?? 0);
    if ($width < 1 || $height < 1 || $width > 12000 || $height > 12000 || ($width * $height) > 40000000) {
        throw new RuntimeException('De fietsafbeelding heeft ongeldige of te grote afmetingen.');
    }

    $uploadDir = ROOT_PATH . '/storage/private/bikes';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0770, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('De map voor fietsafbeeldingen kon niet worden aangemaakt.');
    }

    $storedName = bin2hex(random_bytes(24)) . '.' . $allowed[$mime];
    $destination = $uploadDir . '/' . $storedName;
    if (!move_uploaded_file($tmpName, $destination)) {
        throw new RuntimeException('De fietsafbeelding kon niet veilig worden opgeslagen.');
    }
    @chmod($destination, 0640);

    return [
        'stored_name' => $storedName,
        'original_name' => basename((string) ($file['name'] ?? 'fietsafbeelding.' . $allowed[$mime])),
        'mime_type' => $mime,
        'size_bytes' => $size,
    ];
}

function delete_bike_photo(?string $storedName): void
{
    if (!$storedName) {
        return;
    }

    $path = ROOT_PATH . '/storage/private/bikes/' . basename($storedName);
    if (is_file($path)) {
        @unlink($path);
    }
}

function audit(string $action, string $entityType, ?int $entityId = null, array $details = []): void
{
    $stmt = db()->prepare(
        'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details_json, ip_address, created_at)
         VALUES (:user_id, :action, :entity_type, :entity_id, :details_json, :ip_address, CURRENT_TIMESTAMP)'
    );
    $stmt->execute([
        ':user_id' => current_user()['id'] ?? null,
        ':action' => $action,
        ':entity_type' => $entityType,
        ':entity_id' => $entityId,
        ':details_json' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':ip_address' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
    ]);
}

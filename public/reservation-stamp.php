<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_auth();

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, private');

$id = (int) ($_GET['id'] ?? 0);
$reservation = find_reservation($id);

if (!$reservation) {
    http_response_code(404);
    exit;
}

$createdAt = new DateTimeImmutable((string) $reservation['created_at']);
$createdBy = trim((string) ($reservation['created_by_name'] ?? ''));
$createdEmail = trim((string) ($reservation['created_by_email'] ?? ''));
$closedAt = !empty($reservation['closed_at']) ? new DateTimeImmutable((string) $reservation['closed_at']) : null;
$closedBy = trim((string) ($reservation['closed_by_name'] ?? ''));
$closedEmail = trim((string) ($reservation['closed_by_email'] ?? ''));
?>
<div class="card col-12" data-reservation-stamps>
    <h2>Registratiestempels</h2>
    <dl class="summary-list">
        <dt>Aangemaakt door</dt>
        <dd><?= e($createdBy !== '' ? $createdBy : 'Onbekende gebruiker') ?><?= $createdEmail !== '' ? ' · ' . e($createdEmail) : '' ?></dd>
        <dt>Aangemaakt op</dt>
        <dd><time datetime="<?= e($createdAt->format(DateTimeInterface::ATOM)) ?>"><?= e($createdAt->format('d/m/Y H:i')) ?></time></dd>
        <dt>Afgesloten door</dt>
        <dd><?= $closedAt ? e($closedBy !== '' ? $closedBy : 'Onbekende gebruiker') . ($closedEmail !== '' ? ' · ' . e($closedEmail) : '') : 'Nog niet afgesloten' ?></dd>
        <dt>Afgesloten op</dt>
        <dd>
            <?php if ($closedAt): ?>
                <time datetime="<?= e($closedAt->format(DateTimeInterface::ATOM)) ?>"><?= e($closedAt->format('d/m/Y H:i')) ?></time>
                · <span class="badge status-<?= e((string) $reservation['status']) ?>"><?= e(status_label((string) $reservation['status'])) ?></span>
            <?php else: ?>
                <span class="muted">Wordt automatisch geregistreerd bij Teruggebracht of Geannuleerd.</span>
            <?php endif; ?>
        </dd>
    </dl>
</div>

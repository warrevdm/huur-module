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
<div class="card col-12 reservation-stamps" data-reservation-stamps>
    <h2>Registratiestempels</h2>
    <div class="stamp-grid">
        <div class="stamp-item">
            <span class="stamp-label">Aangemaakt</span>
            <strong><?= e($createdBy !== '' ? $createdBy : 'Onbekende gebruiker') ?></strong>
            <?php if ($createdEmail !== ''): ?><span><?= e($createdEmail) ?></span><?php endif; ?>
            <time datetime="<?= e($createdAt->format(DateTimeInterface::ATOM)) ?>"><?= e($createdAt->format('d/m/Y H:i')) ?></time>
        </div>
        <div class="stamp-item <?= $closedAt ? 'stamp-item-complete' : 'stamp-item-open' ?>">
            <span class="stamp-label">Afsluiting</span>
            <?php if ($closedAt): ?>
                <strong><?= e($closedBy !== '' ? $closedBy : 'Onbekende gebruiker') ?></strong>
                <?php if ($closedEmail !== ''): ?><span><?= e($closedEmail) ?></span><?php endif; ?>
                <time datetime="<?= e($closedAt->format(DateTimeInterface::ATOM)) ?>"><?= e($closedAt->format('d/m/Y H:i')) ?></time>
                <span class="badge status-<?= e((string) $reservation['status']) ?>"><?= e(status_label((string) $reservation['status'])) ?></span>
            <?php else: ?>
                <strong>Nog niet afgesloten</strong>
                <span>De naam-, datum- en tijdstempel wordt geplaatst bij Teruggebracht of Geannuleerd.</span>
            <?php endif; ?>
        </div>
    </div>
</div>

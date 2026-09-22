<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_cashbook();

$timezone = new DateTimeZone('Europe/Brussels');
$today = new DateTimeImmutable('today', $timezone);
$now = new DateTimeImmutable('now', $timezone);

$parseDate = static function (string $value, DateTimeImmutable $fallback) use ($timezone): DateTimeImmutable {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))) {
        return $fallback;
    }
    return $date;
};

$defaultFrom = $today->modify('first day of this month');
$from = $parseDate((string) ($_GET['from'] ?? $defaultFrom->format('Y-m-d')), $defaultFrom);
$to = $parseDate((string) ($_GET['to'] ?? $today->format('Y-m-d')), $today);

if ($to < $from) {
    [$from, $to] = [$to, $from];
}

$fromAt = $from->format('Y-m-d 00:00:00');
$toExclusive = $to->modify('+1 day')->format('Y-m-d 00:00:00');
$nowSql = $now->format('Y-m-d H:i:s');

$paymentStmt = db()->prepare(
    "SELECT p.id, p.paid_at, p.amount, p.method, p.note,
            r.id AS reservation_id, r.start_at, r.end_at, r.status, r.total_price, r.rental_kind,
            c.name AS customer_name,
            u.name AS recorded_by_name
     FROM payment_logs p
     JOIN reservations r ON r.id = p.reservation_id
     JOIN customers c ON c.id = r.customer_id
     LEFT JOIN users u ON u.id = p.recorded_by
     WHERE p.paid_at >= :from_at
       AND p.paid_at < :to_exclusive
     ORDER BY p.paid_at DESC, p.id DESC"
);
$paymentStmt->execute([
    ':from_at' => $fromAt,
    ':to_exclusive' => $toExclusive,
]);
$payments = $paymentStmt->fetchAll();

$receivedTotal = 0.0;
$receivedRentalTotal = 0.0;
$receivedReplacementTotal = 0.0;
$cashTotal = 0.0;
$bancontactTotal = 0.0;
foreach ($payments as $payment) {
    $amount = (float) $payment['amount'];
    $receivedTotal += $amount;
    if ((string) ($payment['rental_kind'] ?? 'rental') === 'replacement') {
        $receivedReplacementTotal += $amount;
    } else {
        $receivedRentalTotal += $amount;
    }
    if ((string) $payment['method'] === 'cash') {
        $cashTotal += $amount;
    } elseif ((string) $payment['method'] === 'bancontact') {
        $bancontactTotal += $amount;
    }
}

$rentalStmt = db()->prepare(
    "SELECT r.id, r.start_at, r.end_at, r.status, r.total_price, r.rental_kind,
            r.replacement_cost_note,
            c.name AS customer_name,
            COALESCE(payments.paid_amount, 0) AS paid_amount,
            COALESCE((
                SELECT group_concat(b.code, ', ')
                FROM reservation_bikes rb
                JOIN bikes b ON b.id = rb.bike_id
                WHERE rb.reservation_id = r.id
            ), '—') AS bikes
     FROM reservations r
     JOIN customers c ON c.id = r.customer_id
     LEFT JOIN (
         SELECT reservation_id, SUM(amount) AS paid_amount
         FROM payment_logs
         GROUP BY reservation_id
     ) payments ON payments.reservation_id = r.id
     WHERE r.rental_kind = 'rental'
       AND r.status != 'cancelled'
       AND r.start_at >= :from_at
       AND r.start_at < :to_exclusive
     ORDER BY r.start_at DESC, r.id DESC"
);
$rentalStmt->execute([
    ':from_at' => $fromAt,
    ':to_exclusive' => $toExclusive,
]);
$rentals = $rentalStmt->fetchAll();

$rentalValue = 0.0;
$periodOutstanding = 0.0;
foreach ($rentals as &$rental) {
    $total = round((float) $rental['total_price'], 2);
    $paid = round((float) $rental['paid_amount'], 2);
    $outstanding = max(0, round($total - $paid, 2));
    $rental['outstanding'] = $outstanding;
    $rentalValue += $total;
    $periodOutstanding += $outstanding;
}
unset($rental);

$receivableSql =
    "SELECT r.id, r.start_at, r.end_at, r.status, r.total_price, r.rental_kind,
            r.replacement_cost_note,
            c.name AS customer_name,
            COALESCE(payments.paid_amount, 0) AS paid_amount,
            COALESCE((
                SELECT group_concat(b.code, ', ')
                FROM reservation_bikes rb
                JOIN bikes b ON b.id = rb.bike_id
                WHERE rb.reservation_id = r.id
            ), '—') AS bikes
     FROM reservations r
     JOIN customers c ON c.id = r.customer_id
     LEFT JOIN (
         SELECT reservation_id, SUM(amount) AS paid_amount
         FROM payment_logs
         GROUP BY reservation_id
     ) payments ON payments.reservation_id = r.id
     WHERE (r.rental_kind = 'rental' OR (r.rental_kind = 'replacement' AND r.total_price > 0))
       AND r.status != 'cancelled'
       AND (r.total_price - COALESCE(payments.paid_amount, 0)) > 0.009";

$forecastStmt = db()->prepare(
    $receivableSql .
    " AND r.end_at >= :now
      AND r.status IN ('reserved', 'confirmed', 'picked_up')
      ORDER BY r.start_at ASC, r.id ASC"
);
$forecastStmt->execute([':now' => $nowSql]);
$forecast = $forecastStmt->fetchAll();

$forecastTotal = 0.0;
foreach ($forecast as &$item) {
    $item['outstanding'] = max(0, round((float) $item['total_price'] - (float) $item['paid_amount'], 2));
    $forecastTotal += (float) $item['outstanding'];
}
unset($item);

$overdueStmt = db()->prepare(
    $receivableSql .
    " AND r.end_at < :now
      ORDER BY r.end_at ASC, r.id ASC"
);
$overdueStmt->execute([':now' => $nowSql]);
$overdue = $overdueStmt->fetchAll();

$overdueTotal = 0.0;
foreach ($overdue as &$item) {
    $item['outstanding'] = max(0, round((float) $item['total_price'] - (float) $item['paid_amount'], 2));
    $overdueTotal += (float) $item['outstanding'];
}
unset($item);

if ((string) ($_GET['export'] ?? '') === 'csv') {
    audit('export', 'cashbook', null, [
        'from' => $from->format('Y-m-d'),
        'to' => $to->format('Y-m-d'),
        'rows' => count($payments),
    ]);

    $filename = 'aab-kasboek-' . $from->format('Ymd') . '-' . $to->format('Ymd') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, private');

    $output = fopen('php://output', 'wb');
    if ($output === false) {
        throw new RuntimeException('CSV-export kon niet worden geopend.');
    }

    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['Datum', 'Type', 'Verhuur', 'Klant', 'Betaalwijze', 'Bedrag', 'Geregistreerd door', 'Notitie'], ';');

    foreach ($payments as $payment) {
        fputcsv($output, [
            (new DateTimeImmutable((string) $payment['paid_at']))->format('d/m/Y H:i'),
            (string) ($payment['rental_kind'] ?? 'rental') === 'replacement' ? 'Vervangkost' : 'Huur',
            '#' . (int) $payment['reservation_id'],
            (string) $payment['customer_name'],
            payment_method_label((string) $payment['method']),
            number_format((float) $payment['amount'], 2, ',', ''),
            (string) ($payment['recorded_by_name'] ?: 'Onbekend'),
            (string) ($payment['note'] ?? ''),
        ], ';');
    }

    fclose($output);
    exit;
}

audit('view', 'cashbook', null, [
    'from' => $from->format('Y-m-d'),
    'to' => $to->format('Y-m-d'),
]);

$currentMonthFrom = $today->modify('first day of this month')->format('Y-m-d');
$currentMonthTo = $today->format('Y-m-d');
$previousMonth = $today->modify('first day of last month');
$previousMonthFrom = $previousMonth->format('Y-m-d');
$previousMonthTo = $previousMonth->modify('last day of this month')->format('Y-m-d');
$yearFrom = $today->setDate((int) $today->format('Y'), 1, 1)->format('Y-m-d');

render_header('Kasboek');
?>
<section class="card">
    <div class="cashbook-toolbar">
        <div>
            <h2>Financieel overzicht verhuur</h2>
            <p class="muted">Effectieve huurbetalingen én expliciet aangerekende vervangkosten. Een vervangfiets zonder kost blijft financieel buiten het kasboek.</p>
        </div>
        <a class="button button-secondary" href="cashbook.php?from=<?= e($from->format('Y-m-d')) ?>&amp;to=<?= e($to->format('Y-m-d')) ?>&amp;export=csv">CSV kasboek</a>
    </div>

    <form method="get" class="cashbook-filters mt-18">
        <div class="field">
            <label for="cashbook-from">Van</label>
            <input id="cashbook-from" type="date" name="from" value="<?= e($from->format('Y-m-d')) ?>">
        </div>
        <div class="field">
            <label for="cashbook-to">Tot en met</label>
            <input id="cashbook-to" type="date" name="to" value="<?= e($to->format('Y-m-d')) ?>">
        </div>
        <button class="button" type="submit">Periode tonen</button>
        <a href="cashbook.php?from=<?= e($currentMonthFrom) ?>&amp;to=<?= e($currentMonthTo) ?>">Deze maand</a>
        <a href="cashbook.php?from=<?= e($previousMonthFrom) ?>&amp;to=<?= e($previousMonthTo) ?>">Vorige maand</a>
        <a href="cashbook.php?from=<?= e($yearFrom) ?>&amp;to=<?= e($currentMonthTo) ?>">Dit jaar</a>
    </form>
</section>

<section class="grid cashbook-kpis">
    <div class="card col-4 cashbook-kpi">
        <span class="muted">Ontvangen in periode</span>
        <span class="stat cashbook-positive">€ <?= number_format($receivedTotal, 2, ',', '.') ?></span>
        <small>Huur € <?= number_format($receivedRentalTotal, 2, ',', '.') ?> · Vervangkost € <?= number_format($receivedReplacementTotal, 2, ',', '.') ?><br>Cash € <?= number_format($cashTotal, 2, ',', '.') ?> · Bancontact € <?= number_format($bancontactTotal, 2, ',', '.') ?></small>
    </div>
    <div class="card col-4 cashbook-kpi">
        <span class="muted">Huurwaarde gestart in periode</span>
        <span class="stat">€ <?= number_format($rentalValue, 2, ',', '.') ?></span>
        <small><?= count($rentals) ?> commerciële huur<?= count($rentals) === 1 ? '' : 'en' ?> · nog open € <?= number_format($periodOutstanding, 2, ',', '.') ?></small>
    </div>
    <div class="card col-4 cashbook-kpi cashbook-forecast">
        <span class="muted">Verwacht nog binnen te komen</span>
        <span class="stat cashbook-positive">€ <?= number_format($forecastTotal, 2, ',', '.') ?></span>
        <small><?= count($forecast) ?> actieve/toekomstige huur<?= count($forecast) === 1 ? '' : 'en' ?> met openstaand saldo</small>
    </div>
</section>

<?php if ($overdueTotal > 0): ?>
<section class="card mt-18 cashbook-overdue">
    <div class="cashbook-section-head">
        <div>
            <h2>Achterstallig/openstaand</h2>
            <p class="muted">Huurperiode is voorbij, maar er staat nog een bedrag open.</p>
        </div>
        <strong class="cashbook-amount cashbook-warning">€ <?= number_format($overdueTotal, 2, ',', '.') ?></strong>
    </div>
    <div class="table-wrap">
        <table class="cashbook-table">
            <thead><tr><th>Dossier</th><th>Type</th><th>Klant</th><th>Periode</th><th>Fiets(en)</th><th>Totaal</th><th>Betaald</th><th>Open</th></tr></thead>
            <tbody>
            <?php foreach ($overdue as $item): ?>
                <tr>
                    <td><a class="cashbook-reservation-link" href="reservation.php?id=<?= (int) $item['id'] ?>"><strong>#<?= (int) $item['id'] ?></strong></a><br><span class="cashbook-subtle"><?= e(status_label((string) $item['status'])) ?></span></td>
                    <td><?= (string) ($item['rental_kind'] ?? 'rental') === 'replacement' ? 'Vervangkost' : 'Huur' ?></td>
                    <td><?= e((string) $item['customer_name']) ?></td>
                    <td><?= e((new DateTimeImmutable((string) $item['start_at']))->format('d/m/Y')) ?> → <?= e((new DateTimeImmutable((string) $item['end_at']))->format('d/m/Y')) ?></td>
                    <td><?= e((string) $item['bikes']) ?></td>
                    <td class="cashbook-amount">€ <?= number_format((float) $item['total_price'], 2, ',', '.') ?></td>
                    <td class="cashbook-amount">€ <?= number_format((float) $item['paid_amount'], 2, ',', '.') ?></td>
                    <td class="cashbook-amount cashbook-warning">€ <?= number_format((float) $item['outstanding'], 2, ',', '.') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<section class="card mt-18">
    <div class="cashbook-section-head">
        <div>
            <h2>Ontvangen betalingen</h2>
            <p class="muted"><?= e($from->format('d/m/Y')) ?> t.e.m. <?= e($to->format('d/m/Y')) ?> · dit is het effectieve kasboek.</p>
        </div>
        <strong class="cashbook-amount">€ <?= number_format($receivedTotal, 2, ',', '.') ?></strong>
    </div>
    <div class="table-wrap">
        <table class="cashbook-table">
            <thead><tr><th>Datum</th><th>Type</th><th>Dossier</th><th>Klant</th><th>Betaalwijze</th><th>Bedrag</th><th>Geregistreerd door</th><th>Notitie</th></tr></thead>
            <tbody>
            <?php foreach ($payments as $payment): ?>
                <tr>
                    <td><?= e((new DateTimeImmutable((string) $payment['paid_at']))->format('d/m/Y H:i')) ?></td>
                    <td><span class="booking-kind booking-kind-<?= (string) ($payment['rental_kind'] ?? 'rental') === 'replacement' ? 'replacement' : 'rental' ?>"><?= (string) ($payment['rental_kind'] ?? 'rental') === 'replacement' ? '↺ Vervangkost' : '€ Huur' ?></span></td>
                    <td><a class="cashbook-reservation-link" href="reservation.php?id=<?= (int) $payment['reservation_id'] ?>"><strong>#<?= (int) $payment['reservation_id'] ?></strong></a></td>
                    <td><?= e((string) $payment['customer_name']) ?></td>
                    <td><span class="cashbook-method"><?= e(payment_method_label((string) $payment['method'])) ?></span></td>
                    <td class="cashbook-amount">€ <?= number_format((float) $payment['amount'], 2, ',', '.') ?></td>
                    <td><?= e((string) ($payment['recorded_by_name'] ?: 'Onbekend')) ?></td>
                    <td><?= e((string) ($payment['note'] ?: '—')) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$payments): ?>
                <tr><td colspan="8" class="muted">Geen geregistreerde betalingen in deze periode.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card mt-18">
    <div class="cashbook-section-head">
        <div>
            <h2>Huren gestart in periode</h2>
            <p class="muted">Commerciële huren met totaalprijs, reeds ontvangen bedrag en openstaand saldo.</p>
        </div>
        <strong class="cashbook-amount">€ <?= number_format($rentalValue, 2, ',', '.') ?></strong>
    </div>
    <div class="table-wrap">
        <table class="cashbook-table">
            <thead><tr><th>Verhuur</th><th>Klant</th><th>Periode</th><th>Fiets(en)</th><th>Status</th><th>Totaal</th><th>Betaald</th><th>Open</th></tr></thead>
            <tbody>
            <?php foreach ($rentals as $rental): ?>
                <tr>
                    <td><a class="cashbook-reservation-link" href="reservation.php?id=<?= (int) $rental['id'] ?>"><strong>#<?= (int) $rental['id'] ?></strong></a></td>
                    <td><?= e((string) $rental['customer_name']) ?></td>
                    <td><?= e((new DateTimeImmutable((string) $rental['start_at']))->format('d/m/Y')) ?> → <?= e((new DateTimeImmutable((string) $rental['end_at']))->format('d/m/Y')) ?></td>
                    <td><?= e((string) $rental['bikes']) ?></td>
                    <td><span class="badge status-<?= e((string) $rental['status']) ?>"><?= e(status_label((string) $rental['status'])) ?></span></td>
                    <td class="cashbook-amount">€ <?= number_format((float) $rental['total_price'], 2, ',', '.') ?></td>
                    <td class="cashbook-amount">€ <?= number_format((float) $rental['paid_amount'], 2, ',', '.') ?></td>
                    <td class="cashbook-amount <?= (float) $rental['outstanding'] > 0 ? 'cashbook-warning' : 'cashbook-positive' ?>">€ <?= number_format((float) $rental['outstanding'], 2, ',', '.') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rentals): ?>
                <tr><td colspan="8" class="muted">Geen betalende huren gestart in deze periode.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card mt-18 cashbook-forecast">
    <div class="cashbook-section-head">
        <div>
            <h2>Verwachte inkomsten</h2>
            <p class="muted">Nog te ontvangen saldo van bevestigde, gereserveerde of lopende commerciële huren.</p>
        </div>
        <strong class="cashbook-amount cashbook-positive">€ <?= number_format($forecastTotal, 2, ',', '.') ?></strong>
    </div>
    <div class="table-wrap">
        <table class="cashbook-table">
            <thead><tr><th>Dossier</th><th>Type</th><th>Klant</th><th>Periode</th><th>Fiets(en)</th><th>Status</th><th>Totaal</th><th>Reeds betaald</th><th>Verwacht</th></tr></thead>
            <tbody>
            <?php foreach ($forecast as $item): ?>
                <tr>
                    <td><a class="cashbook-reservation-link" href="reservation.php?id=<?= (int) $item['id'] ?>"><strong>#<?= (int) $item['id'] ?></strong></a></td>
                    <td><?= (string) ($item['rental_kind'] ?? 'rental') === 'replacement' ? 'Vervangkost' : 'Huur' ?></td>
                    <td><?= e((string) $item['customer_name']) ?></td>
                    <td><?= e((new DateTimeImmutable((string) $item['start_at']))->format('d/m/Y')) ?> → <?= e((new DateTimeImmutable((string) $item['end_at']))->format('d/m/Y')) ?></td>
                    <td><?= e((string) $item['bikes']) ?></td>
                    <td><span class="badge status-<?= e((string) $item['status']) ?>"><?= e(status_label((string) $item['status'])) ?></span></td>
                    <td class="cashbook-amount">€ <?= number_format((float) $item['total_price'], 2, ',', '.') ?></td>
                    <td class="cashbook-amount">€ <?= number_format((float) $item['paid_amount'], 2, ',', '.') ?></td>
                    <td class="cashbook-amount cashbook-positive">€ <?= number_format((float) $item['outstanding'], 2, ',', '.') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$forecast): ?>
                <tr><td colspan="9" class="muted">Geen openstaande actieve of toekomstige inkomsten.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php render_footer();

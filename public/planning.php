<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_auth();

$days = max(7, min(28, (int) ($_GET['days'] ?? 14)));
$start = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($_GET['start'] ?? date('Y-m-d'))) ?: new DateTimeImmutable('today');
$end = $start->modify("+{$days} days");
$allBikes = all_bikes(true);
$selectedCategory = trim((string) ($_GET['category'] ?? ''));
$focus = (string) ($_GET['focus'] ?? '');
$focus = in_array($focus, ['pickups', 'returns', 'active'], true) ? $focus : '';
$today = date('Y-m-d');

$categories = [];
foreach ($allBikes as $bike) {
    $category = trim((string) ($bike['category'] ?? ''));
    if ($category !== '' && !in_array($category, $categories, true)) {
        $categories[] = $category;
    }
}
natcasesort($categories);
$categories = array_values($categories);

if ($selectedCategory !== '' && !in_array($selectedCategory, $categories, true)) {
    $selectedCategory = '';
}

$focusReservationIds = [];
$focusBikeIds = [];

if ($focus !== '') {
    $focusWhere = match ($focus) {
        'pickups' => "date(start_at) = :today AND status IN ('reserved', 'confirmed')",
        'returns' => "date(end_at) = :today AND status IN ('picked_up', 'confirmed')",
        'active' => "status = 'picked_up'",
    };

    $focusStmt = db()->prepare(
        "SELECT id, start_at, end_at
         FROM reservations
         WHERE {$focusWhere}
         ORDER BY start_at, id"
    );
    $focusParams = $focus === 'active' ? [] : [':today' => $today];
    $focusStmt->execute($focusParams);
    $focusReservations = $focusStmt->fetchAll();

    if ($focusReservations) {
        $focusReservationIds = array_map(
            static fn (array $reservation): int => (int) $reservation['id'],
            $focusReservations
        );

        $focusStart = min(array_map(
            static fn (array $reservation): string => substr((string) $reservation['start_at'], 0, 10),
            $focusReservations
        ));
        $focusEnd = max(array_map(
            static fn (array $reservation): string => substr((string) $reservation['end_at'], 0, 10),
            $focusReservations
        ));

        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $focusStart) ?: $start;
        $lastVisibleDay = DateTimeImmutable::createFromFormat('!Y-m-d', $focusEnd) ?: $start;
        $days = max(1, (int) $start->diff($lastVisibleDay)->days + 1);
        $end = $start->modify("+{$days} days");

        $placeholders = implode(',', array_fill(0, count($focusReservationIds), '?'));
        $bikeStmt = db()->prepare(
            "SELECT DISTINCT bike_id
             FROM reservation_bikes
             WHERE reservation_id IN ({$placeholders})"
        );
        $bikeStmt->execute($focusReservationIds);
        $focusBikeIds = array_map('intval', $bikeStmt->fetchAll(PDO::FETCH_COLUMN));
    }
}

$candidateBikes = $focus === ''
    ? $allBikes
    : array_values(array_filter(
        $allBikes,
        static fn (array $bike): bool => in_array((int) $bike['id'], $focusBikeIds, true)
    ));

$bikes = $selectedCategory === ''
    ? $candidateBikes
    : array_values(array_filter(
        $candidateBikes,
        static fn (array $bike): bool => (string) ($bike['category'] ?? '') === $selectedCategory
    ));

$events = reservations_for_range($start, $end);
if ($focus !== '') {
    $events = array_values(array_filter(
        $events,
        static fn (array $event): bool => in_array((int) $event['id'], $focusReservationIds, true)
    ));
}
$counts = reservation_counts();
$byBike = [];
foreach ($events as $event) {
    $byBike[(int) $event['bike_id']][] = $event;
}

$categoryParam = $selectedCategory !== '' ? '&category=' . rawurlencode($selectedCategory) : '';
$focusParam = $focus !== '' ? '&focus=' . rawurlencode($focus) : '';
$focusLabels = [
    'pickups' => 'Afhalingen vandaag',
    'returns' => 'Retours vandaag',
    'active' => 'Verhuren onderweg',
];

render_header('Verhuurplanning');
?>
<section class="grid planning-stats" aria-label="Snelfilters planning">
    <a class="card col-4 planning-stat-card <?= $focus === 'pickups' ? 'is-active' : '' ?>" href="planning.php?focus=pickups<?= e($categoryParam) ?>">
        <span class="stat"><?= (int) ($counts['pickups'] ?? 0) ?></span>
        <span class="muted">afhalingen vandaag</span>
        <span class="planning-stat-action">Toon fietsen →</span>
    </a>
    <a class="card col-4 planning-stat-card <?= $focus === 'returns' ? 'is-active' : '' ?>" href="planning.php?focus=returns<?= e($categoryParam) ?>">
        <span class="stat"><?= (int) ($counts['returns'] ?? 0) ?></span>
        <span class="muted">retours vandaag</span>
        <span class="planning-stat-action">Toon fietsen →</span>
    </a>
    <a class="card col-4 planning-stat-card <?= $focus === 'active' ? 'is-active' : '' ?>" href="planning.php?focus=active<?= e($categoryParam) ?>">
        <span class="stat"><?= (int) ($counts['active'] ?? 0) ?></span>
        <span class="muted">verhuren onderweg</span>
        <span class="planning-stat-action">Toon fietsen →</span>
    </a>
</section>

<section class="card mt-18">
    <div class="planning-toolbar">
        <div class="actions">
            <?php if ($focus !== ''): ?>
                <a class="button button-secondary" href="planning.php?days=14<?= e($categoryParam) ?>">← Volledige planning</a>
                <span class="planning-focus-label"><?= e($focusLabels[$focus]) ?> · volledige huurperiode</span>
            <?php else: ?>
                <a class="button button-secondary" href="planning.php?start=<?= e($start->modify("-{$days} days")->format('Y-m-d')) ?>&amp;days=<?= $days ?><?= e($categoryParam) ?>">← Vorige</a>
                <a class="button button-secondary" href="planning.php?days=<?= $days ?><?= e($categoryParam) ?>">Vandaag</a>
                <a class="button button-secondary" href="planning.php?start=<?= e($end->format('Y-m-d')) ?>&amp;days=<?= $days ?><?= e($categoryParam) ?>">Volgende →</a>
            <?php endif; ?>
        </div>
        <div class="actions">
            <a href="planning.php?days=7<?= e($categoryParam) ?>">7 dagen</a>
            <a href="planning.php?days=14<?= e($categoryParam) ?>">14 dagen</a>
            <a href="planning.php?days=28<?= e($categoryParam) ?>">28 dagen</a>
            <a class="button" href="reservation-new.php">+ Nieuwe verhuur</a>
        </div>
    </div>

    <?php if ($categories): ?>
        <div class="planning-category-filter" aria-label="Filter planning op soort fiets">
            <span class="legend-title">Soort fiets:</span>
            <a class="button <?= $selectedCategory === '' ? '' : 'button-secondary' ?>" href="planning.php?start=<?= e($start->format('Y-m-d')) ?>&amp;days=<?= $days ?><?= e($focusParam) ?>">Alles</a>
            <?php foreach ($categories as $category): ?>
                <a class="button <?= $selectedCategory === $category ? '' : 'button-secondary' ?>" href="planning.php?start=<?= e($start->format('Y-m-d')) ?>&amp;days=<?= $days ?>&amp;category=<?= rawurlencode($category) ?><?= e($focusParam) ?>"><?= e($category) ?></a>
            <?php endforeach; ?>
            <span class="muted"><?= count($bikes) ?> van <?= count($allBikes) ?> fiets(en) zichtbaar</span>
        </div>
    <?php endif; ?>

    <div class="planning-legend" aria-label="Legende planning">
        <span class="legend-title">Reservatie:</span>
        <?php foreach (['reserved', 'confirmed', 'picked_up', 'returned'] as $status): ?>
            <span class="legend-item"><i class="legend-swatch status-<?= e($status) ?>"></i><?= e(status_label($status)) ?></span>
        <?php endforeach; ?>
        <span class="legend-title">Type:</span>
        <span class="legend-item"><i class="booking-kind booking-kind-rental">€</i>Huurfiets · betaling</span>
        <span class="legend-item"><i class="booking-kind booking-kind-replacement">↺</i>Vervangfiets · geen huurbetaling</span>
        <span class="legend-title">Dossier:</span>
        <span class="legend-item"><i class="booking-status-icon booking-contract-signed">✍✓</i>Contract ondertekend</span>
        <span class="legend-item"><i class="booking-status-icon booking-contract-open">✍!</i>Nog niet ondertekend</span>
        <span class="legend-item"><i class="booking-status-icon booking-payment-paid">€✓</i>Betaald</span>
        <span class="legend-item"><i class="booking-status-icon booking-payment-partial">€½</i>Deels betaald</span>
        <span class="legend-item"><i class="booking-status-icon booking-payment-open">€!</i>Nog te betalen</span>
        <span class="legend-item"><i class="booking-status-icon booking-payment-unpriced">€—</i>Prijs niet ingesteld</span>
        <span class="legend-title">Fiets:</span>
        <span class="legend-item"><i class="legend-swatch bike-active"></i>Beschikbaar</span>
        <span class="legend-item"><i class="legend-swatch bike-maintenance"></i>Onderhoud</span>
        <span class="legend-item"><i class="legend-swatch bike-inactive"></i>Inactief</span>
    </div>

    <?php if (!$allBikes): ?>
        <div class="alert alert-warning">Voeg eerst een fiets toe.</div>
        <a class="button" href="bikes.php">Fiets toevoegen</a>
    <?php elseif (!$bikes): ?>
        <div class="alert alert-warning"><?= $focus !== '' ? 'Geen fietsen gevonden voor ' . e(strtolower($focusLabels[$focus])) . '.' : 'Geen fietsen gevonden voor deze categorie.' ?></div>
    <?php else: ?>
        <div class="planning-wrap"><table class="planning">
            <thead><tr><th class="bike-cell">Fiets</th>
            <?php for ($i = 0; $i < $days; $i++): $date = $start->modify("+{$i} days"); ?>
                <th class="<?= $date->format('Y-m-d') === date('Y-m-d') ? 'today' : '' ?> <?= in_array((int) $date->format('N'), [6, 7], true) ? 'weekend' : '' ?>"><?= e($date->format('D')) ?><br><strong><?= e($date->format('d/m')) ?></strong></th>
            <?php endfor; ?></tr></thead>
            <tbody>
            <?php foreach ($bikes as $bike):
                $cursor = 0;
                $bikeEvents = $byBike[(int) $bike['id']] ?? [];
                $bikeStatus = (string) $bike['status'];
                $planable = $bikeStatus === 'active';
            ?>
                <tr class="bike-row bike-row-<?= e($bikeStatus) ?>">
                    <td class="bike-cell">
                        <div class="planning-bike-heading">
                            <strong><?= e($bike['name']) ?></strong>
                            <span class="badge badge-<?= e($bikeStatus) ?>"><?= e(bike_status_label($bikeStatus)) ?></span>
                        </div>
                        <span class="muted"><?= e($bike['code']) ?> · <?= e($bike['category']) ?> · <?= e(bike_usage_type_label((string) ($bike['usage_type'] ?? 'rental'))) ?></span>
                        <?php if (!$planable): ?><span class="bike-block-reason"><?= $bikeStatus === 'maintenance' ? 'Niet inplanbaar tijdens onderhoud' : 'Niet inplanbaar zolang inactief' ?></span><?php endif; ?>
                    </td>
                    <?php while ($cursor < $days):
                        $day = $start->modify("+{$cursor} days");
                        $dayEnd = $day->modify('+1 day');
                        $active = null;
                        foreach ($bikeEvents as $candidate) {
                            if (new DateTimeImmutable($candidate['start_at']) < $dayEnd && new DateTimeImmutable($candidate['end_at']) > $day) {
                                $active = $candidate;
                                break;
                            }
                        }
                        if ($active):
                            $activeEnd = new DateTimeImmutable($active['end_at']);
                            $span = 1;
                            while ($cursor + $span < $days && $start->modify('+' . ($cursor + $span) . ' days') < $activeEnd) {
                                $span++;
                            }

                            $contractSigned = !empty($active['contract_signed_at']);
                            $rentalKind = (string) ($active['rental_kind'] ?? 'rental');
                            $isReplacement = $rentalKind === 'replacement';
                            $kindLabel = $isReplacement ? 'Vervang' : 'Huur';
                            $kindIcon = $isReplacement ? '↺' : '€';
                            $totalPrice = round((float) ($active['total_price'] ?? 0), 2);
                            $paidAmount = round((float) ($active['paid_amount'] ?? 0), 2);
                            if ($isReplacement && $totalPrice <= 0) {
                                $paymentClass = 'booking-payment-not-required';
                                $paymentIcon = '€0';
                                $paymentTitle = 'Geen kost gekoppeld aan deze vervangfiets';
                            } elseif ($totalPrice <= 0) {
                                $paymentClass = 'booking-payment-unpriced';
                                $paymentIcon = '€—';
                                $paymentTitle = 'Totaalprijs nog niet ingesteld';
                            } elseif ($paidAmount + 0.009 >= $totalPrice) {
                                $paymentClass = 'booking-payment-paid';
                                $paymentIcon = '€✓';
                                $paymentTitle = ($isReplacement ? 'Vervangkost betaald: € ' : 'Volledig betaald: € ') . number_format($paidAmount, 2, ',', '.');
                            } elseif ($paidAmount > 0) {
                                $paymentClass = 'booking-payment-partial';
                                $paymentIcon = '€½';
                                $paymentTitle = ($isReplacement ? 'Vervangkost deels betaald: € ' : 'Deels betaald: € ') . number_format($paidAmount, 2, ',', '.') . ' van € ' . number_format($totalPrice, 2, ',', '.');
                            } else {
                                $paymentClass = 'booking-payment-open';
                                $paymentIcon = '€!';
                                $paymentTitle = ($isReplacement ? 'Vervangkost open: € ' : 'Nog niet betaald: € ') . number_format($totalPrice, 2, ',', '.');
                            }
                    ?>
                        <td colspan="<?= $span ?>">
                            <a class="booking-block booking-type-<?= $isReplacement ? 'replacement' : 'rental' ?> status-<?= e($active['status']) ?>" href="reservation.php?id=<?= (int) $active['id'] ?>" data-customer-name="<?= e($active['customer_name']) ?>" title="<?= e($active['customer_name']) ?> · <?= e($kindLabel) ?> · <?= e((new DateTimeImmutable($active['start_at']))->format('d/m/Y H:i')) ?> → <?= e($activeEnd->format('d/m/Y H:i')) ?>">
                                <span class="booking-kind-row">
                                    <span class="booking-kind booking-kind-<?= $isReplacement ? 'replacement' : 'rental' ?>"><span aria-hidden="true"><?= e($kindIcon) ?></span> <?= e($kindLabel) ?></span>
                                    <?php if ($isReplacement): ?><span class="booking-no-payment"><?= $totalPrice > 0 ? 'kost € ' . number_format($totalPrice, 2, ',', '.') : 'geen kost' ?></span><?php endif; ?>
                                </span>
                                <span class="booking-title-row">
                                    <strong><?= e($active['customer_name']) ?></strong>
                                    <span class="booking-status-icons" aria-label="Contract- en betaalstatus">
                                        <?php if (!$isReplacement): ?>
                                            <span class="booking-status-icon <?= $contractSigned ? 'booking-contract-signed' : 'booking-contract-open' ?>" title="<?= $contractSigned ? 'Contract ondertekend' : 'Contract nog niet ondertekend' ?>" aria-label="<?= $contractSigned ? 'Contract ondertekend' : 'Contract nog niet ondertekend' ?>"><?= $contractSigned ? '✍✓' : '✍!' ?></span>
                                        <?php endif; ?>
                                        <span class="booking-status-icon <?= e($paymentClass) ?>" title="<?= e($paymentTitle) ?>" aria-label="<?= e($paymentTitle) ?>"><?= e($paymentIcon) ?></span>
                                    </span>
                                </span>
                                <span><?= e((new DateTimeImmutable($active['start_at']))->format('d/m H:i')) ?> → <?= e($activeEnd->format('d/m H:i')) ?></span>
                                <span><?= e(status_label($active['status'])) ?><?= $active['document_id'] ? ' · ID ✓' : '' ?></span>
                            </a>
                        </td>
                    <?php $cursor += $span; else: ?>
                        <td class="day-cell <?= in_array((int) $day->format('N'), [6, 7], true) ? 'weekend' : '' ?> <?= !$planable ? 'blocked-slot' : '' ?>">
                            <?php if ($planable): ?>
                                <a class="empty-slot" href="reservation-new.php?bike_id=<?= (int) $bike['id'] ?>&amp;start_date=<?= e($day->format('Y-m-d')) ?>" title="Nieuwe verhuur"></a>
                            <?php else: ?>
                                <span class="blocked-slot-mark" title="<?= e(bike_status_label($bikeStatus)) ?>">×</span>
                            <?php endif; ?>
                        </td>
                    <?php $cursor++; endif; endwhile; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php render_footer();

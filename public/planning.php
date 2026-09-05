<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_auth();

$days = max(7, min(28, (int) ($_GET['days'] ?? 14)));
$start = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($_GET['start'] ?? date('Y-m-d'))) ?: new DateTimeImmutable('today');
$end = $start->modify("+{$days} days");
$allBikes = all_bikes(true);
$selectedCategory = trim((string) ($_GET['category'] ?? ''));

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

$bikes = $selectedCategory === ''
    ? $allBikes
    : array_values(array_filter(
        $allBikes,
        static fn (array $bike): bool => (string) ($bike['category'] ?? '') === $selectedCategory
    ));

$events = reservations_for_range($start, $end);
$counts = reservation_counts();
$byBike = [];
foreach ($events as $event) {
    $byBike[(int) $event['bike_id']][] = $event;
}

$categoryParam = $selectedCategory !== '' ? '&category=' . rawurlencode($selectedCategory) : '';

render_header('Verhuurplanning');
?>
<section class="grid">
    <div class="card col-4"><span class="stat"><?= (int) ($counts['pickups'] ?? 0) ?></span><span class="muted">afhalingen vandaag</span></div>
    <div class="card col-4"><span class="stat"><?= (int) ($counts['returns'] ?? 0) ?></span><span class="muted">retours vandaag</span></div>
    <div class="card col-4"><span class="stat"><?= (int) ($counts['active'] ?? 0) ?></span><span class="muted">verhuren onderweg</span></div>
</section>

<section class="card mt-18">
    <div class="planning-toolbar">
        <div class="actions">
            <a class="button button-secondary" href="planning.php?start=<?= e($start->modify("-{$days} days")->format('Y-m-d')) ?>&amp;days=<?= $days ?><?= e($categoryParam) ?>">← Vorige</a>
            <a class="button button-secondary" href="planning.php?days=<?= $days ?><?= e($categoryParam) ?>">Vandaag</a>
            <a class="button button-secondary" href="planning.php?start=<?= e($end->format('Y-m-d')) ?>&amp;days=<?= $days ?><?= e($categoryParam) ?>">Volgende →</a>
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
            <a class="button <?= $selectedCategory === '' ? '' : 'button-secondary' ?>" href="planning.php?start=<?= e($start->format('Y-m-d')) ?>&amp;days=<?= $days ?>">Alles</a>
            <?php foreach ($categories as $category): ?>
                <a class="button <?= $selectedCategory === $category ? '' : 'button-secondary' ?>" href="planning.php?start=<?= e($start->format('Y-m-d')) ?>&amp;days=<?= $days ?>&amp;category=<?= rawurlencode($category) ?>"><?= e($category) ?></a>
            <?php endforeach; ?>
            <span class="muted"><?= count($bikes) ?> van <?= count($allBikes) ?> fiets(en) zichtbaar</span>
        </div>
    <?php endif; ?>

    <div class="planning-legend" aria-label="Legende planning">
        <span class="legend-title">Reservatie:</span>
        <?php foreach (['reserved', 'confirmed', 'picked_up', 'returned'] as $status): ?>
            <span class="legend-item"><i class="legend-swatch status-<?= e($status) ?>"></i><?= e(status_label($status)) ?></span>
        <?php endforeach; ?>
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
        <div class="alert alert-warning">Geen fietsen gevonden voor deze categorie.</div>
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
                            $totalPrice = round((float) ($active['total_price'] ?? 0), 2);
                            $paidAmount = round((float) ($active['paid_amount'] ?? 0), 2);
                            if ($totalPrice <= 0) {
                                $paymentClass = 'booking-payment-unpriced';
                                $paymentIcon = '€—';
                                $paymentTitle = 'Totaalprijs nog niet ingesteld';
                            } elseif ($paidAmount + 0.009 >= $totalPrice) {
                                $paymentClass = 'booking-payment-paid';
                                $paymentIcon = '€✓';
                                $paymentTitle = 'Volledig betaald: € ' . number_format($paidAmount, 2, ',', '.');
                            } elseif ($paidAmount > 0) {
                                $paymentClass = 'booking-payment-partial';
                                $paymentIcon = '€½';
                                $paymentTitle = 'Deels betaald: € ' . number_format($paidAmount, 2, ',', '.') . ' van € ' . number_format($totalPrice, 2, ',', '.');
                            } else {
                                $paymentClass = 'booking-payment-open';
                                $paymentIcon = '€!';
                                $paymentTitle = 'Nog niet betaald: € ' . number_format($totalPrice, 2, ',', '.');
                            }
                    ?>
                        <td colspan="<?= $span ?>">
                            <a class="booking-block status-<?= e($active['status']) ?>" href="reservation.php?id=<?= (int) $active['id'] ?>">
                                <span class="booking-title-row">
                                    <strong><?= e($active['customer_name']) ?></strong>
                                    <span class="booking-status-icons" aria-label="Contract- en betaalstatus">
                                        <span class="booking-status-icon <?= $contractSigned ? 'booking-contract-signed' : 'booking-contract-open' ?>" title="<?= $contractSigned ? 'Contract ondertekend' : 'Contract nog niet ondertekend' ?>" aria-label="<?= $contractSigned ? 'Contract ondertekend' : 'Contract nog niet ondertekend' ?>"><?= $contractSigned ? '✍✓' : '✍!' ?></span>
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

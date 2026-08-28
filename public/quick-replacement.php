<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_auth();

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$name = trim((string) ($_POST['customer_name'] ?? ''));
$selectedBikeId = (int) ($_POST['bike_id'] ?? 0);

$bikes = all_bikes(true);
usort($bikes, static function (array $a, array $b): int {
    $categoryCompare = strnatcasecmp((string) ($a['category'] ?? ''), (string) ($b['category'] ?? ''));
    if ($categoryCompare !== 0) {
        return $categoryCompare;
    }
    return strnatcasecmp((string) ($a['code'] ?? ''), (string) ($b['code'] ?? ''));
});

$categories = [];
foreach ($bikes as $bike) {
    $category = trim((string) ($bike['category'] ?? ''));
    if ($category !== '' && !in_array($category, $categories, true)) {
        $categories[] = $category;
    }
}
natcasesort($categories);
$categories = array_values($categories);

$timezone = new DateTimeZone('Europe/Brussels');
$now = new DateTimeImmutable('now', $timezone);
$defaultReturnDate = $now->modify('+1 day')->format('Y-m-d');
$returnDate = trim((string) ($_POST['return_date'] ?? $defaultReturnDate));
$returnAt = parse_datetime($returnDate, '17:00');

if ($method === 'POST') {
    verify_csrf();

    if ($name === '' || $selectedBikeId < 1 || $returnDate === '') {
        flash('error', 'Vul de naam en retourdatum in en kies een fiets.');
        redirect('quick-replacement.php');
    }

    if (!$returnAt || $returnAt <= $now) {
        flash('error', 'Kies een geldige retourdatum in de toekomst.');
        redirect('quick-replacement.php');
    }

    $bike = find_bike($selectedBikeId);
    if (!$bike) {
        flash('error', 'Deze fiets bestaat niet meer.');
        redirect('quick-replacement.php');
    }

    if ((string) ($bike['status'] ?? '') !== 'active') {
        flash('error', $bike['code'] . ' — ' . $bike['name'] . ' is momenteel niet beschikbaar.');
        redirect('quick-replacement.php');
    }

    $startAt = $now->format('Y-m-d H:i:s');
    $endAt = $returnAt->format('Y-m-d H:i:s');
    if (reservation_conflicts($selectedBikeId, $startAt, $endAt)) {
        flash('error', $bike['code'] . ' — ' . $bike['name'] . ' is niet beschikbaar tot ' . $returnAt->format('d/m/Y') . '.');
        redirect('quick-replacement.php');
    }

    try {
        db()->beginTransaction();

        $customerStmt = db()->prepare('INSERT INTO customers (name, email, phone, address) VALUES (:name, NULL, NULL, NULL)');
        $customerStmt->execute([':name' => $name]);
        $customerId = (int) db()->lastInsertId();

        $reservationStmt = db()->prepare(
            'INSERT INTO reservations
             (bike_id, customer_id, identity_document_id, start_at, end_at, status, total_price, notes, created_by,
              eid_physical_checked, eid_photo_match)
             VALUES
             (:bike_id, :customer_id, NULL, :start_at, :end_at, :status, 0, :notes, :created_by, 0, 0)'
        );
        $reservationStmt->execute([
            ':bike_id' => $selectedBikeId,
            ':customer_id' => $customerId,
            ':start_at' => $startAt,
            ':end_at' => $endAt,
            ':status' => 'picked_up',
            ':notes' => 'Snelle fietsregistratie via werkplaats. Retour voorzien op ' . $returnAt->format('d/m/Y') . ' om 17:00.',
            ':created_by' => (int) current_user()['id'],
        ]);
        $reservationId = (int) db()->lastInsertId();

        $bikeStmt = db()->prepare(
            'INSERT INTO reservation_bikes (reservation_id, bike_id, daily_rate)
             VALUES (:reservation_id, :bike_id, 0)'
        );
        $bikeStmt->execute([
            ':reservation_id' => $reservationId,
            ':bike_id' => $selectedBikeId,
        ]);

        audit('create', 'quick_replacement', $reservationId, [
            'bike_id' => $selectedBikeId,
            'customer_name' => $name,
            'category' => (string) ($bike['category'] ?? ''),
            'usage_type' => (string) ($bike['usage_type'] ?? ''),
            'start_at' => $startAt,
            'return_at' => $endAt,
            'total_price' => 0,
        ]);

        db()->commit();
        flash('success', $bike['code'] . ' — ' . $bike['name'] . ' is meegegeven aan ' . $name . ' tot ' . $returnAt->format('d/m/Y') . '.');
        redirect('planning.php');
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        flash('error', 'De fiets kon niet worden geregistreerd.');
        redirect('quick-replacement.php');
    }
}

$availabilityEnd = ($returnAt && $returnAt > $now)
    ? $returnAt
    : $now->modify('+1 day')->setTime(17, 0);
$availability = bike_availability(
    $now->format('Y-m-d H:i:s'),
    $availabilityEnd->format('Y-m-d H:i:s')
);

render_header('Snelle vervangfiets');
?>
<section class="quick-replacement-shell" data-quick-replacement data-availability-url="api-bike-availability.php" data-start-date="<?= e($now->format('Y-m-d')) ?>" data-start-time="<?= e($now->format('H:i')) ?>">
    <div class="card quick-replacement-card">
        <div class="quick-replacement-heading">
            <div>
                <span class="quick-replacement-kicker">Werkplaats</span>
                <h2>Snel een fiets meegeven</h2>
                <p class="muted">Naam, retourdatum en fiets. De fiets wordt meteen als afgehaald geregistreerd.</p>
            </div>
            <a class="button button-secondary" href="planning.php">Terug naar planning</a>
        </div>

        <form method="post" class="stack quick-replacement-form">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">

            <div class="quick-replacement-basics">
                <div class="field quick-name-field">
                    <label for="quick-customer-name">Naam klant *</label>
                    <input id="quick-customer-name" name="customer_name" required autofocus autocomplete="name" placeholder="Voornaam en naam" value="<?= e($name) ?>">
                </div>
                <div class="field quick-return-field">
                    <label for="quick-return-date">Retourdatum *</label>
                    <input id="quick-return-date" name="return_date" type="date" required min="<?= e($now->format('Y-m-d')) ?>" value="<?= e($returnDate) ?>" data-quick-return-date>
                    <span class="help">Retouruur wordt automatisch op 17:00 gezet.</span>
                </div>
            </div>

            <div class="field">
                <div class="actions actions-between">
                    <label>Kies fiets *</label>
                    <span class="help" data-quick-period-label>Beschikbaarheid wordt gecontroleerd tot <?= e($availabilityEnd->format('d/m/Y')) ?> om 17:00.</span>
                </div>

                <div class="quick-category-filters" role="group" aria-label="Filter op soort fiets">
                    <button class="quick-filter is-active" type="button" data-quick-filter="all" aria-pressed="true">Alles</button>
                    <?php foreach ($categories as $category): ?>
                        <button class="quick-filter" type="button" data-quick-filter="<?= e(mb_strtolower($category, 'UTF-8')) ?>" aria-pressed="false"><?= e($category) ?></button>
                    <?php endforeach; ?>
                </div>
                <div class="quick-filter-summary" data-quick-filter-summary aria-live="polite"></div>

                <?php if (!$bikes): ?>
                    <div class="alert alert-warning">Er zijn nog geen fietsen toegevoegd.</div>
                <?php else: ?>
                    <div class="quick-bike-grid">
                        <?php foreach ($bikes as $index => $bike):
                            $state = $availability[(int) $bike['id']] ?? ['available' => false, 'reason' => 'Niet beschikbaar'];
                            $available = !empty($state['available']);
                            $category = trim((string) ($bike['category'] ?? 'Andere')) ?: 'Andere';
                        ?>
                            <label
                                class="quick-bike-card <?= $available ? '' : 'quick-bike-card-disabled' ?>"
                                data-quick-bike-card
                                data-bike-id="<?= (int) $bike['id'] ?>"
                                data-category="<?= e(mb_strtolower($category, 'UTF-8')) ?>"
                                data-available="<?= $available ? '1' : '0' ?>"
                            >
                                <input
                                    type="radio"
                                    name="bike_id"
                                    value="<?= (int) $bike['id'] ?>"
                                    <?= $selectedBikeId === (int) $bike['id'] ? 'checked' : '' ?>
                                    <?= !$available ? 'disabled' : '' ?>
                                    required
                                >
                                <span class="quick-bike-image">
                                    <?php if (!empty($bike['photo_stored_name'])): ?>
                                        <img src="<?= e(bike_photo_src($bike, 240)) ?>" alt="<?= e((string) $bike['name']) ?>" loading="<?= $index < 4 ? 'eager' : 'lazy' ?>" decoding="async">
                                    <?php else: ?>
                                        <span>Geen foto</span>
                                    <?php endif; ?>
                                </span>
                                <span class="quick-bike-info">
                                    <span class="quick-bike-topline">
                                        <strong><?= e((string) $bike['code']) ?></strong>
                                        <span class="quick-bike-category"><?= e($category) ?></span>
                                    </span>
                                    <span><?= e((string) $bike['name']) ?></span>
                                    <small><?= e(bike_usage_type_label((string) ($bike['usage_type'] ?? 'rental'))) ?></small>
                                    <span class="quick-bike-state <?= $available ? 'quick-bike-state-available' : 'quick-bike-state-unavailable' ?>" data-quick-bike-state>
                                        <?= $available ? 'Beschikbaar' : e((string) ($state['reason'] ?? 'Niet beschikbaar')) ?>
                                    </span>
                                </span>
                                <span class="quick-bike-check">✓</span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="quick-replacement-actions">
                <button class="button quick-replacement-submit" type="submit" <?= !$bikes ? 'disabled' : '' ?>>Fiets registreren</button>
            </div>
        </form>
    </div>
</section>
<?php render_footer();

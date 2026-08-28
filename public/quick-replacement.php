<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_auth();

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$name = trim((string) ($_POST['customer_name'] ?? ''));
$selectedBikeId = (int) ($_POST['bike_id'] ?? 0);

$replacementBikes = array_values(array_filter(
    all_bikes(true),
    static fn (array $bike): bool => in_array(
        (string) ($bike['usage_type'] ?? ''),
        ['replacement', 'replacement_rental'],
        true
    )
));

usort($replacementBikes, static function (array $a, array $b): int {
    return strnatcasecmp((string) ($a['code'] ?? ''), (string) ($b['code'] ?? ''));
});

$now = new DateTimeImmutable('now', new DateTimeZone('Europe/Brussels'));
$provisionalEnd = $now->modify('+30 days');

if ($method === 'POST') {
    verify_csrf();

    if ($name === '' || $selectedBikeId < 1) {
        flash('error', 'Vul de naam in en kies een vervangfiets.');
        redirect('quick-replacement.php');
    }

    $bike = find_bike($selectedBikeId);
    if (!$bike || !in_array((string) ($bike['usage_type'] ?? ''), ['replacement', 'replacement_rental'], true)) {
        flash('error', 'Deze fiets kan niet als vervangfiets worden geregistreerd.');
        redirect('quick-replacement.php');
    }

    if ((string) ($bike['status'] ?? '') !== 'active') {
        flash('error', $bike['code'] . ' — ' . $bike['name'] . ' is momenteel niet beschikbaar.');
        redirect('quick-replacement.php');
    }

    $startAt = $now->format('Y-m-d H:i:s');
    $endAt = $provisionalEnd->format('Y-m-d H:i:s');
    if (reservation_conflicts($selectedBikeId, $startAt, $endAt)) {
        flash('error', $bike['code'] . ' — ' . $bike['name'] . ' is al ingepland in de komende 30 dagen.');
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
            ':notes' => 'Snelle vervangfiets via werkplaats. Voorlopige einddatum: ' . $provisionalEnd->format('d/m/Y H:i') . '.',
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
            'start_at' => $startAt,
            'provisional_end_at' => $endAt,
            'total_price' => 0,
        ]);

        db()->commit();
        flash('success', $bike['code'] . ' — ' . $bike['name'] . ' is meegegeven aan ' . $name . '.');
        redirect('planning.php');
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        flash('error', 'De vervangfiets kon niet worden geregistreerd.');
        redirect('quick-replacement.php');
    }
}

$availability = bike_availability(
    $now->format('Y-m-d H:i:s'),
    $provisionalEnd->format('Y-m-d H:i:s')
);

render_header('Snelle vervangfiets');
?>
<section class="quick-replacement-shell">
    <div class="card quick-replacement-card">
        <div class="quick-replacement-heading">
            <div>
                <span class="quick-replacement-kicker">Werkplaats</span>
                <h2>Vervangfiets meegeven</h2>
                <p class="muted">Alleen naam en fiets. De fiets wordt meteen als afgehaald geregistreerd.</p>
            </div>
            <a class="button button-secondary" href="planning.php">Terug naar planning</a>
        </div>

        <form method="post" class="stack quick-replacement-form">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">

            <div class="field quick-name-field">
                <label for="quick-customer-name">Naam klant *</label>
                <input id="quick-customer-name" name="customer_name" required autofocus autocomplete="name" placeholder="Voornaam en naam" value="<?= e($name) ?>">
            </div>

            <div class="field">
                <div class="actions actions-between">
                    <label>Kies vervangfiets *</label>
                    <span class="help">Voorlopig geblokkeerd voor 30 dagen of tot je hem als teruggebracht markeert.</span>
                </div>

                <?php if (!$replacementBikes): ?>
                    <div class="alert alert-warning">Er zijn nog geen fietsen ingesteld als vervangfiets.</div>
                <?php else: ?>
                    <div class="quick-bike-grid">
                        <?php foreach ($replacementBikes as $index => $bike):
                            $state = $availability[(int) $bike['id']] ?? ['available' => false, 'reason' => 'Niet beschikbaar'];
                            $available = !empty($state['available']);
                        ?>
                            <label class="quick-bike-card <?= $available ? '' : 'quick-bike-card-disabled' ?>">
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
                                    <strong><?= e((string) $bike['code']) ?></strong>
                                    <span><?= e((string) $bike['name']) ?></span>
                                    <small><?= e((string) $bike['category']) ?></small>
                                    <span class="quick-bike-state <?= $available ? 'quick-bike-state-available' : 'quick-bike-state-unavailable' ?>">
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
                <button class="button quick-replacement-submit" type="submit" <?= !$replacementBikes ? 'disabled' : '' ?>>Vervangfiets registreren</button>
            </div>
        </form>
    </div>
</section>
<?php render_footer();

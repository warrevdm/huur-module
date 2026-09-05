<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_auth();

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$startDate = (string) ($_GET['start_date'] ?? $_POST['start_date'] ?? date('Y-m-d'));
$startTime = (string) ($_POST['start_time'] ?? '09:00');
$endDate = (string) ($_POST['end_date'] ?? (new DateTimeImmutable($startDate))->modify('+1 day')->format('Y-m-d'));
$endTime = (string) ($_POST['end_time'] ?? '17:00');
$selectedBikeIds = array_values(array_unique(array_filter(array_map(
    'intval',
    (array) ($_POST['bike_ids'] ?? (isset($_GET['bike_id']) ? [(int) $_GET['bike_id']] : []))
))));

if ($method === 'POST') {
    verify_csrf();

    $startAt = parse_datetime($startDate, $startTime);
    $endAt = parse_datetime($endDate, $endTime);
    $name = trim((string) ($_POST['customer_name'] ?? ''));
    $email = trim((string) ($_POST['customer_email'] ?? ''));
    $totalPrice = max(0, round((float) ($_POST['total_price'] ?? 0), 2));
    $priceCalculationMode = (string) ($_POST['price_calculation_mode'] ?? 'manual') === 'auto' ? 'auto' : 'manual';
    $initialPaymentMethod = (string) ($_POST['initial_payment_method'] ?? '');
    $initialPaymentAmount = max(0, round((float) ($_POST['initial_payment_amount'] ?? 0), 2));
    $eidPhysicalChecked = isset($_POST['eid_physical_checked']);
    $eidPhotoMatch = isset($_POST['eid_photo_match']);
    $eidVerified = $eidPhysicalChecked && $eidPhotoMatch;

    if (!$selectedBikeIds || count($selectedBikeIds) > 25) {
        flash('error', 'Selecteer minstens één en maximaal 25 fietsen.');
        redirect('reservation-new.php');
    }
    if (!$startAt || !$endAt || $endAt <= $startAt || !$name || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Vul de huurperiode en verplichte klantgegevens correct in.');
        redirect('reservation-new.php');
    }

    $selectedBikes = [];
    foreach ($selectedBikeIds as $bikeId) {
        $bike = find_bike($bikeId);
        if (!$bike) {
            flash('error', 'Een geselecteerde fiets bestaat niet meer.');
            redirect('reservation-new.php');
        }
        if ((string) $bike['status'] !== 'active') {
            flash('error', $bike['code'] . ' — ' . $bike['name'] . ' kan niet worden ingepland: ' . bike_status_label((string) $bike['status']) . '.');
            redirect('reservation-new.php');
        }
        if (reservation_conflicts($bikeId, $startAt->format('Y-m-d H:i:s'), $endAt->format('Y-m-d H:i:s'))) {
            flash('error', $bike['code'] . ' — ' . $bike['name'] . ' is niet meer beschikbaar in deze periode.');
            redirect('reservation-new.php');
        }
        $selectedBikes[] = $bike;
    }

    $priceQuote = rental_price_quote($selectedBikes, $startAt, $endAt);
    if ($priceCalculationMode === 'auto') {
        if (!$priceQuote['complete']) {
            flash('error', 'De automatische prijs kon niet voor alle geselecteerde fietsen worden berekend. Vul de totaalprijs manueel in.');
            redirect('reservation-new.php');
        }
        $totalPrice = (float) $priceQuote['total'];
    }

    if ($eidPhysicalChecked !== $eidPhotoMatch) {
        flash('error', 'Identiteitscontrole onvolledig: bevestig zowel de fysieke eID als de visuele fotovergelijking, of laat beide uitgevinkt.');
        redirect('reservation-new.php');
    }

    if ($initialPaymentMethod !== '' && !in_array($initialPaymentMethod, ['bancontact', 'cash'], true)) {
        flash('error', 'Kies Bancontact, cash of nog niet betaald.');
        redirect('reservation-new.php');
    }
    if ($initialPaymentMethod !== '' && $initialPaymentAmount <= 0) {
        flash('error', 'Vul een geldig betaald bedrag in.');
        redirect('reservation-new.php');
    }
    if ($initialPaymentAmount > $totalPrice && $totalPrice > 0) {
        flash('error', 'Het betaalde bedrag kan niet hoger zijn dan de totaalprijs.');
        redirect('reservation-new.php');
    }

    try {
        db()->beginTransaction();

        $stmt = db()->prepare('INSERT INTO customers (name,email,phone,address) VALUES (:name,:email,:phone,:address)');
        $stmt->execute([
            ':name' => $name,
            ':email' => $email,
            ':phone' => trim((string) ($_POST['customer_phone'] ?? '')) ?: null,
            ':address' => trim((string) ($_POST['customer_address'] ?? '')) ?: null,
        ]);
        $customerId = (int) db()->lastInsertId();

        $retention = trim((string) ($_POST['retention_until'] ?? ''))
            ?: $endAt->modify('+' . (int) env('ID_RETENTION_DAYS', '30') . ' days')->format('Y-m-d');
        $documentId = upload_identity_document($_FILES['identity_document'] ?? [], $customerId, $retention);

        $primaryBike = $selectedBikes[0];
        $stmt = db()->prepare(
            'INSERT INTO reservations
             (bike_id,customer_id,identity_document_id,start_at,end_at,status,total_price,notes,created_by,
              eid_physical_checked,eid_photo_match,eid_checked_by,eid_checked_at)
             VALUES (:bike,:customer,:document,:start,:end,:status,:price,:notes,:user,
                     :eid_physical,:eid_photo,:eid_user,:eid_at)'
        );
        $stmt->execute([
            ':bike' => (int) $primaryBike['id'],
            ':customer' => $customerId,
            ':document' => $documentId,
            ':start' => $startAt->format('Y-m-d H:i:s'),
            ':end' => $endAt->format('Y-m-d H:i:s'),
            ':status' => ($_POST['status'] ?? 'reserved') === 'confirmed' ? 'confirmed' : 'reserved',
            ':price' => $totalPrice,
            ':notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
            ':user' => (int) current_user()['id'],
            ':eid_physical' => $eidVerified ? 1 : 0,
            ':eid_photo' => $eidVerified ? 1 : 0,
            ':eid_user' => $eidVerified ? (int) current_user()['id'] : null,
            ':eid_at' => $eidVerified ? (new DateTimeImmutable('now', new DateTimeZone('Europe/Brussels')))->format('Y-m-d H:i:s') : null,
        ]);
        $reservationId = (int) db()->lastInsertId();

        $bikeStmt = db()->prepare(
            'INSERT INTO reservation_bikes (reservation_id, bike_id, daily_rate)
             VALUES (:reservation_id, :bike_id, :daily_rate)'
        );
        foreach ($selectedBikes as $bike) {
            $pricingRule = rental_pricing_rule($bike);
            $reservedDailyRate = $pricingRule !== null
                ? (float) $pricingRule['day_rate']
                : (float) $bike['daily_rate'];
            $bikeStmt->execute([
                ':reservation_id' => $reservationId,
                ':bike_id' => (int) $bike['id'],
                ':daily_rate' => $reservedDailyRate,
            ]);
        }

        if ($initialPaymentMethod !== '') {
            $stmt = db()->prepare(
                'INSERT INTO payment_logs (reservation_id, amount, method, note, paid_at, recorded_by)
                 VALUES (:reservation_id, :amount, :method, :note, CURRENT_TIMESTAMP, :recorded_by)'
            );
            $stmt->execute([
                ':reservation_id' => $reservationId,
                ':amount' => $initialPaymentAmount,
                ':method' => $initialPaymentMethod,
                ':note' => 'Betaling geregistreerd bij aanmaak verhuur',
                ':recorded_by' => (int) current_user()['id'],
            ]);
        }

        db()->commit();
        audit('create', 'reservation', $reservationId, [
            'bike_ids' => $selectedBikeIds,
            'bike_count' => count($selectedBikeIds),
            'has_identity_document' => $documentId !== null,
            'eid_physical_checked' => $eidVerified,
            'eid_photo_match' => $eidVerified,
            'eid_checked_by' => $eidVerified ? (int) current_user()['id'] : null,
            'initial_payment_method' => $initialPaymentMethod ?: null,
            'initial_payment_amount' => $initialPaymentMethod !== '' ? $initialPaymentAmount : 0,
            'price_calculation_mode' => $priceCalculationMode,
            'billable_days' => (int) $priceQuote['days'],
            'calculated_total' => $priceQuote['complete'] ? (float) $priceQuote['total'] : null,
            'stored_total' => $totalPrice,
        ]);
        flash('success', count($selectedBikeIds) . ' fiets(en) ingepland. Controleer nu het gezamenlijke contract.');
        redirect('contract.php?reservation_id=' . $reservationId);
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        flash('error', $e instanceof RuntimeException ? $e->getMessage() : 'Opslaan is mislukt.');
        redirect('reservation-new.php');
    }
}

$bikes = all_bikes(true);
$startAt = parse_datetime($startDate, $startTime);
$endAt = parse_datetime($endDate, $endTime);
$availability = ($startAt && $endAt && $endAt > $startAt)
    ? bike_availability($startAt->format('Y-m-d H:i:s'), $endAt->format('Y-m-d H:i:s'))
    : [];

render_header('Nieuwe verhuur');
?>
<form method="post" enctype="multipart/form-data" class="rental-create-layout" data-reservation-form data-availability-url="api-bike-availability.php" data-visual-bike-picker>
    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="price_calculation_mode" value="manual" data-price-calculation-mode>

    <div class="rental-create-main">
        <section class="card rental-step-card rental-period-card">
            <div class="rental-step-heading">
                <span class="rental-step-number">1</span>
                <div>
                    <h2>Wanneer?</h2>
                    <p class="muted">Kies afhaal- en retourmoment. Beschikbaarheid past zich automatisch aan.</p>
                </div>
            </div>
            <div class="rental-period-grid">
                <div class="field"><label>Startdatum</label><input name="start_date" type="date" value="<?= e($startDate) ?>" required></div>
                <div class="field"><label>Afhalen</label><input name="start_time" type="time" value="<?= e($startTime) ?>" required></div>
                <div class="field"><label>Einddatum</label><input name="end_date" type="date" value="<?= e($endDate) ?>" required></div>
                <div class="field"><label>Retour</label><input name="end_time" type="time" value="<?= e($endTime) ?>" required></div>
            </div>
            <div class="availability-message availability-loading" data-availability-message aria-live="polite">Beschikbaarheid controleren…</div>
        </section>

        <section class="card rental-step-card">
            <div class="rental-step-heading rental-step-heading-split">
                <div class="rental-step-heading-main">
                    <span class="rental-step-number">2</span>
                    <div>
                        <h2>Kies de fiets</h2>
                        <p class="muted">Klik op een fiets om ze toe te voegen. Meerdere fietsen kan zonder Ctrl of Command.</p>
                    </div>
                </div>
                <span class="rental-selected-count" data-rental-selected-count>0 geselecteerd</span>
            </div>

            <div class="rental-bike-toolbar">
                <input type="search" placeholder="Zoek code, model of categorie…" data-rental-bike-search aria-label="Fiets zoeken">
                <div class="rental-bike-filters" data-rental-bike-filters>
                    <button type="button" class="button button-secondary is-active" data-rental-filter="ALL">Alles</button>
                    <button type="button" class="button button-secondary" data-rental-filter="H">Huur</button>
                    <button type="button" class="button button-secondary" data-rental-filter="V">Vervang</button>
                    <button type="button" class="button button-secondary" data-rental-filter="T">Test</button>
                </div>
            </div>

            <select name="bike_ids[]" multiple required data-bike-select data-visual-picker class="rental-native-select" aria-hidden="true" tabindex="-1">
                <?php foreach ($bikes as $bike):
                    $state = $availability[(int) $bike['id']] ?? [
                        'available' => (string) $bike['status'] === 'active',
                        'reason' => (string) $bike['status'] === 'active' ? null : bike_status_label((string) $bike['status']),
                    ];
                    $selected = in_array((int) $bike['id'], $selectedBikeIds, true);
                    $suffix = $state['available'] ? 'BESCHIKBAAR' : strtoupper((string) ($state['reason'] ?: 'NIET BESCHIKBAAR'));
                    $usageType = bike_usage_type_label((string) ($bike['usage_type'] ?? 'rental'));
                    $baseLabel = $bike['code'] . ' — ' . $bike['name'] . ' (' . $bike['category'] . ' · ' . $usageType . ')';
                    $pricingRule = rental_pricing_rule($bike);
                    $photoUrl = !empty($bike['photo_stored_name']) ? bike_photo_src($bike, 480) : '';
                ?>
                    <option value="<?= (int) $bike['id'] ?>"
                            data-base-label="<?= e($baseLabel) ?>"
                            data-bike-code="<?= e((string) $bike['code']) ?>"
                            data-bike-name="<?= e((string) $bike['name']) ?>"
                            data-bike-category="<?= e((string) $bike['category']) ?>"
                            data-bike-usage-type="<?= e((string) ($bike['usage_type'] ?? 'rental')) ?>"
                            data-bike-usage-label="<?= e($usageType) ?>"
                            data-bike-photo="<?= e($photoUrl) ?>"
                            data-price-supported="<?= $pricingRule !== null ? '1' : '0' ?>"
                            data-price-day="<?= $pricingRule !== null ? e(number_format((float) $pricingRule['day_rate'], 2, '.', '')) : '' ?>"
                            data-price-week="<?= $pricingRule !== null ? e(number_format((float) $pricingRule['week_rate'], 2, '.', '')) : '' ?>"
                            data-price-label="<?= $pricingRule !== null ? e((string) $pricingRule['label']) : 'Prijs manueel' ?>"
                            <?= $selected ? 'selected' : '' ?>
                            <?= !$state['available'] ? 'disabled' : '' ?>><?= e($baseLabel . ' · ' . $suffix) ?></option>
                <?php endforeach; ?>
            </select>

            <div class="rental-bike-picker" data-rental-bike-picker>
                <?php foreach ($bikes as $index => $bike):
                    $state = $availability[(int) $bike['id']] ?? [
                        'available' => (string) $bike['status'] === 'active',
                        'reason' => (string) $bike['status'] === 'active' ? null : bike_status_label((string) $bike['status']),
                    ];
                    $selected = in_array((int) $bike['id'], $selectedBikeIds, true);
                    $pricingRule = rental_pricing_rule($bike);
                    $usageType = bike_usage_type_label((string) ($bike['usage_type'] ?? 'rental'));
                    $photoUrl = !empty($bike['photo_stored_name']) ? bike_photo_src($bike, 480) : '';
                ?>
                    <button type="button"
                            class="rental-bike-option <?= $selected ? 'is-selected' : '' ?> <?= !$state['available'] ? 'is-unavailable' : '' ?>"
                            data-rental-bike-card
                            data-bike-id="<?= (int) $bike['id'] ?>"
                            data-bike-code="<?= e((string) $bike['code']) ?>"
                            data-bike-name="<?= e((string) $bike['name']) ?>"
                            data-bike-category="<?= e((string) $bike['category']) ?>"
                            data-bike-group="<?= e(substr(strtoupper(trim((string) $bike['code'])), 0, 1)) ?>"
                            aria-pressed="<?= $selected ? 'true' : 'false' ?>"
                            <?= !$state['available'] ? 'disabled' : '' ?>>
                        <span class="rental-bike-image">
                            <?php if ($photoUrl !== ''): ?>
                                <img src="<?= e($photoUrl) ?>" alt="" loading="<?= $index < 6 ? 'eager' : 'lazy' ?>" decoding="async">
                            <?php else: ?>
                                <span class="rental-bike-no-photo">Geen foto</span>
                            <?php endif; ?>
                            <span class="rental-bike-check" aria-hidden="true">✓</span>
                        </span>
                        <span class="rental-bike-content">
                            <span class="rental-bike-topline"><strong><?= e((string) $bike['code']) ?></strong><span data-rental-card-status><?= $state['available'] ? 'Beschikbaar' : e((string) ($state['reason'] ?: 'Niet beschikbaar')) ?></span></span>
                            <span class="rental-bike-name"><?= e((string) $bike['name']) ?></span>
                            <span class="rental-bike-meta"><?= e((string) $bike['category']) ?> · <?= e($usageType) ?></span>
                            <span class="rental-bike-price"><?= $pricingRule !== null ? e((string) $pricingRule['label']) : 'Prijs manueel' ?></span>
                        </span>
                    </button>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="card rental-step-card">
            <div class="rental-step-heading">
                <span class="rental-step-number">3</span>
                <div>
                    <h2>Wie huurt?</h2>
                    <p class="muted">Enkel de noodzakelijke klantgegevens staan standaard open.</p>
                </div>
            </div>
            <div class="form-grid rental-customer-grid">
                <div class="field"><label>Volledige naam *</label><input name="customer_name" autocomplete="name" required></div>
                <div class="field"><label>E-mail *</label><input name="customer_email" type="email" autocomplete="email" required></div>
                <div class="field"><label>Telefoon</label><input name="customer_phone" type="tel" autocomplete="tel"></div>
                <div class="field"><label>Adres</label><input name="customer_address" autocomplete="street-address"></div>
            </div>

            <details class="rental-extra-details">
                <summary>Extra gegevens: eID, document en notities</summary>
                <div class="rental-extra-content">
                    <div class="field field-full">
                        <label>Identiteitscontrole bij afhaling</label>
                        <label class="checkbox-row"><input type="checkbox" name="eid_physical_checked" value="1"> Fysieke Belgische eID gecontroleerd</label>
                        <label class="checkbox-row"><input type="checkbox" name="eid_photo_match" value="1"> Foto op fysieke eID komt overeen met huurder</label>
                        <span class="help">Bij bevestiging worden medewerker en tijdstip geregistreerd. Rijksregisternummer en eID-foto worden niet opgeslagen.</span>
                    </div>
                    <div class="form-grid">
                        <div class="field"><label>Identiteitsdocument (optioneel)</label><input name="identity_document" type="file" accept="image/jpeg,image/png,application/pdf"></div>
                        <div class="field"><label>Automatisch verwijderen na</label><input name="retention_until" type="date" value="<?= e((new DateTimeImmutable($endDate))->modify('+' . (int) env('ID_RETENTION_DAYS', '30') . ' days')->format('Y-m-d')) ?>"></div>
                        <div class="field field-full"><label>Interne notities</label><textarea name="notes" placeholder="Optionele informatie voor showroom of werkplaats"></textarea></div>
                    </div>
                </div>
            </details>
        </section>
    </div>

    <aside class="card rental-summary-card">
        <div class="rental-summary-heading">
            <span class="rental-step-number">✓</span>
            <div><h2>Samenvatting</h2><p class="muted">Alles op één plek vóór je opslaat.</p></div>
        </div>

        <div class="rental-summary-period" data-rental-summary-period>Periode wordt berekend…</div>
        <div class="rental-summary-bikes" data-rental-summary-bikes>
            <div class="rental-summary-empty">Nog geen fiets geselecteerd.</div>
        </div>

        <button class="button button-secondary rental-hidden-price-button" type="button" data-calculate-rental-price>Prijs herberekenen</button>
        <div class="availability-message" data-price-breakdown aria-live="polite">Selecteer fiets(en) en een geldige huurperiode.</div>

        <div class="rental-total-block">
            <span>Totaalprijs</span>
            <div class="rental-total-input"><span>€</span><input name="total_price" type="number" min="0" step="0.01" value="0" data-total-price aria-label="Totaalprijs"></div>
            <small>Je kan het bedrag manueel aanpassen bij uitzonderingen.</small>
        </div>

        <div class="field"><label>Status</label><select name="status"><option value="reserved">Gereserveerd</option><option value="confirmed">Bevestigd</option></select></div>
        <div class="field"><label>Betaling</label><select name="initial_payment_method" data-payment-method><option value="">Nog niet betaald</option><option value="bancontact">Bancontact</option><option value="cash">Cash</option></select></div>
        <div class="field"><label>Betaald bedrag</label><input name="initial_payment_amount" type="number" min="0" step="0.01" value="0" data-payment-amount></div>

        <button class="button button-full rental-primary-submit" type="submit">Verhuur aanmaken</button>
        <a class="button button-secondary button-full" href="planning.php">Annuleren</a>
        <p class="help rental-contract-note">Na opslaan ga je rechtstreeks naar het gezamenlijke contract.</p>
    </aside>
</form>
<?php render_footer();

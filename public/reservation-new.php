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
            $bikeStmt->execute([
                ':reservation_id' => $reservationId,
                ':bike_id' => (int) $bike['id'],
                ':daily_rate' => (float) $bike['daily_rate'],
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
<section class="card">
<form method="post" enctype="multipart/form-data" class="stack" data-reservation-form data-availability-url="api-bike-availability.php">
    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">

    <div class="form-grid">
        <div class="field field-full">
            <label>Fietsen * <span class="muted">(meerdere selecties mogelijk)</span></label>
            <select name="bike_ids[]" multiple size="<?= min(12, max(6, count($bikes))) ?>" required data-bike-select>
                <?php foreach ($bikes as $bike):
                    $state = $availability[(int) $bike['id']] ?? [
                        'available' => (string) $bike['status'] === 'active',
                        'reason' => (string) $bike['status'] === 'active' ? null : bike_status_label((string) $bike['status']),
                    ];
                    $selected = in_array((int) $bike['id'], $selectedBikeIds, true);
                    $suffix = $state['available'] ? 'BESCHIKBAAR' : strtoupper((string) ($state['reason'] ?: 'NIET BESCHIKBAAR'));
                    $usageType = bike_usage_type_label((string) ($bike['usage_type'] ?? 'rental'));
                    $baseLabel = $bike['code'] . ' — ' . $bike['name'] . ' (' . $bike['category'] . ' · ' . $usageType . ')';
                ?>
                    <option value="<?= (int) $bike['id'] ?>"
                            data-base-label="<?= e($baseLabel) ?>"
                            <?= $selected ? 'selected' : '' ?>
                            <?= !$state['available'] ? 'disabled' : '' ?>><?= e($baseLabel . ' · ' . $suffix) ?></option>
                <?php endforeach; ?>
            </select>
            <span class="help">Windows: houd Ctrl ingedrukt. Mac: houd Command ingedrukt. De beschikbaarheid vernieuwt automatisch bij elke datum- of uurwijziging.</span>
            <div class="availability-message" data-availability-message aria-live="polite"></div>
        </div>
        <div class="field"><label>Startdatum *</label><input name="start_date" type="date" value="<?= e($startDate) ?>" required></div>
        <div class="field"><label>Afhaaluur *</label><input name="start_time" type="time" value="<?= e($startTime) ?>" required></div>
        <div class="field"><label>Einddatum *</label><input name="end_date" type="date" value="<?= e($endDate) ?>" required></div>
        <div class="field"><label>Retouruur *</label><input name="end_time" type="time" value="<?= e($endTime) ?>" required></div>
    </div>

    <hr><h2>Klantgegevens</h2>
    <div class="form-grid">
        <div class="field"><label>Volledige naam *</label><input name="customer_name" required></div>
        <div class="field"><label>Telefoon</label><input name="customer_phone" type="tel"></div>
        <div class="field"><label>E-mail voor contractkopie *</label><input name="customer_email" type="email" required></div>
        <div class="field"><label>Adres</label><input name="customer_address"></div>

        <div class="field field-full">
            <label>Identiteitscontrole bij afhaling</label>
            <label class="checkbox-row">
                <input type="checkbox" name="eid_physical_checked" value="1">
                Fysieke Belgische eID gecontroleerd
            </label>
            <label class="checkbox-row">
                <input type="checkbox" name="eid_photo_match" value="1">
                Foto op de fysieke eID visueel overeenkomstig met de huurder
            </label>
            <span class="help">
                Bij bevestiging registreert het systeem automatisch de ingelogde medewerker en het tijdstip.
                Rijksregisternummer en eID-foto worden niet opgeslagen.
            </span>
        </div>

        <div class="field field-full"><label>Identiteitsdocument (optioneel)</label><input name="identity_document" type="file" accept="image/jpeg,image/png,application/pdf"><span class="help">Gebruik bij voorkeur visuele identificatie. Upload alleen met geldige wettelijke basis en bewaartermijn.</span></div>
        <div class="field"><label>Automatisch verwijderen na</label><input name="retention_until" type="date" value="<?= e((new DateTimeImmutable($endDate))->modify('+' . (int) env('ID_RETENTION_DAYS', '30') . ' days')->format('Y-m-d')) ?>"></div>
    </div>

    <hr><h2>Prijs en betaling</h2>
    <div class="form-grid">
        <div class="field"><label>Status</label><select name="status"><option value="reserved">Gereserveerd</option><option value="confirmed">Bevestigd</option></select></div>
        <div class="field"><label>Totaalprijs</label><input name="total_price" type="number" min="0" step="0.01" value="0"></div>
        <div class="field"><label>Betaling bij reservatie</label><select name="initial_payment_method" data-payment-method><option value="">Nog niet betaald</option><option value="bancontact">Bancontact</option><option value="cash">Cash</option></select></div>
        <div class="field"><label>Betaald bedrag</label><input name="initial_payment_amount" type="number" min="0" step="0.01" value="0" data-payment-amount></div>
        <div class="field field-full"><label>Interne notities</label><textarea name="notes"></textarea></div>
    </div>

    <div class="actions"><button class="button">Opslaan en gezamenlijk contract opmaken</button><a class="button button-secondary" href="planning.php">Annuleren</a></div>
</form>
</section>
<?php render_footer();

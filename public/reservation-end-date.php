<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_auth();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$reservation = find_reservation($id);
if (!$reservation) {
    http_response_code(404);
    exit('Verhuur niet gevonden.');
}

if (!in_array((string) $reservation['status'], ['confirmed', 'picked_up'], true)) {
    flash('error', 'De einddatum kan alleen bij een actieve verhuring worden aangepast.');
    redirect('reservation.php?id=' . $id);
}

$currentEnd = new DateTimeImmutable((string) $reservation['end_at']);
$currentStart = new DateTimeImmutable((string) $reservation['start_at']);
$newDateValue = trim((string) ($_POST['end_date'] ?? $currentEnd->format('Y-m-d')));

if ((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();

    $newDate = DateTimeImmutable::createFromFormat('!Y-m-d', $newDateValue, new DateTimeZone('Europe/Brussels'));
    $errors = DateTimeImmutable::getLastErrors();
    if (!$newDate || (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))) {
        flash('error', 'Kies een geldige einddatum.');
        redirect('reservation-end-date.php?id=' . $id);
    }

    $newEnd = $newDate->setTime(
        (int) $currentEnd->format('H'),
        (int) $currentEnd->format('i'),
        (int) $currentEnd->format('s')
    );

    if ($newEnd <= $currentStart) {
        flash('error', 'De einddatum moet na de start van de verhuring liggen.');
        redirect('reservation-end-date.php?id=' . $id);
    }

    if ($newEnd->format('Y-m-d H:i:s') === $currentEnd->format('Y-m-d H:i:s')) {
        flash('success', 'De einddatum was al ingesteld op deze datum.');
        redirect('reservation.php?id=' . $id);
    }

    $conflicts = [];
    $bikeIds = [];
    foreach ((array) ($reservation['bikes'] ?? []) as $bike) {
        $bikeId = (int) ($bike['id'] ?? 0);
        if ($bikeId < 1) {
            continue;
        }
        $bikeIds[] = $bikeId;
        if (reservation_conflicts(
            $bikeId,
            $currentStart->format('Y-m-d H:i:s'),
            $newEnd->format('Y-m-d H:i:s'),
            $id
        )) {
            $conflicts[] = (string) ($bike['code'] ?? $bikeId) . ' — ' . (string) ($bike['name'] ?? 'fiets');
        }
    }

    if ($conflicts) {
        flash('error', 'Einddatum niet aangepast. Conflict met een volgende reservatie voor: ' . implode(', ', $conflicts) . '.');
        redirect('reservation-end-date.php?id=' . $id);
    }

    try {
        db()->beginTransaction();
        $stmt = db()->prepare(
            'UPDATE reservations
             SET end_at = :end_at, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND status IN (\'confirmed\', \'picked_up\')'
        );
        $stmt->execute([
            ':end_at' => $newEnd->format('Y-m-d H:i:s'),
            ':id' => $id,
        ]);

        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('De verhuring is intussen gewijzigd.');
        }

        audit('update_end_date', 'reservation', $id, [
            'old_end_at' => $currentEnd->format('Y-m-d H:i:s'),
            'new_end_at' => $newEnd->format('Y-m-d H:i:s'),
            'bike_ids' => $bikeIds,
            'return_time_preserved' => $currentEnd->format('H:i:s'),
        ]);

        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        flash('error', 'De einddatum kon niet worden opgeslagen.');
        redirect('reservation-end-date.php?id=' . $id);
    }

    flash('success', 'Einddatum aangepast van ' . $currentEnd->format('d/m/Y') . ' naar ' . $newEnd->format('d/m/Y') . '.');
    redirect('reservation.php?id=' . $id);
}

render_header('Einddatum aanpassen');
?>
<section class="card" style="max-width:680px;margin:0 auto">
    <div class="actions actions-between">
        <div>
            <h2><?= e((string) $reservation['bike_summary']) ?></h2>
            <p class="muted"><?= e((string) $reservation['customer_name']) ?></p>
        </div>
        <span class="badge status-<?= e((string) $reservation['status']) ?>"><?= e(status_label((string) $reservation['status'])) ?></span>
    </div>

    <dl class="summary-list mt-18">
        <dt>Start</dt><dd><?= e($currentStart->format('d/m/Y H:i')) ?></dd>
        <dt>Huidige einddatum</dt><dd><?= e($currentEnd->format('d/m/Y H:i')) ?></dd>
    </dl>

    <hr>
    <form method="post" class="stack">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="field">
            <label for="end-date">Nieuwe einddatum *</label>
            <input id="end-date" name="end_date" type="date" required value="<?= e($newDateValue) ?>">
            <span class="help">Alleen de datum verandert. Het retouruur blijft <?= e($currentEnd->format('H:i')) ?>.</span>
        </div>
        <div class="actions">
            <button class="button" type="submit">Einddatum opslaan</button>
            <a class="button button-secondary" href="reservation.php?id=<?= $id ?>">Annuleren</a>
        </div>
    </form>
</section>
<?php render_footer();

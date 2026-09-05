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

$contract = find_contract_by_reservation($id);

if ((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'confirm-eid-check') {
        if (!empty($reservation['eid_checked_at'])) {
            flash('error', 'De eID-identiteitscontrole is voor dit dossier al bevestigd.');
            redirect('reservation.php?id=' . $id . '#identiteitscontrole');
        }

        $physicalChecked = isset($_POST['eid_physical_checked']);
        $photoMatch = isset($_POST['eid_photo_match']);

        if (!$physicalChecked || !$photoMatch) {
            flash('error', 'Bevestig zowel de fysieke eID-controle als de visuele fotovergelijking.');
            redirect('reservation.php?id=' . $id . '#identiteitscontrole');
        }

        $checkedAt = (new DateTimeImmutable('now', new DateTimeZone('Europe/Brussels')))->format('Y-m-d H:i:s');
        $checkedBy = (int) current_user()['id'];

        $stmt = db()->prepare(
            'UPDATE reservations
             SET eid_physical_checked = 1,
                 eid_photo_match = 1,
                 eid_checked_by = :checked_by,
                 eid_checked_at = :checked_at,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND eid_checked_at IS NULL'
        );
        $stmt->execute([
            ':checked_by' => $checkedBy,
            ':checked_at' => $checkedAt,
            ':id' => $id,
        ]);

        if ($stmt->rowCount() !== 1) {
            flash('error', 'De identiteitscontrole kon niet worden vastgelegd of was al geregistreerd.');
            redirect('reservation.php?id=' . $id . '#identiteitscontrole');
        }

        audit('confirm_eid_identity_check', 'reservation', $id, [
            'eid_physical_checked' => true,
            'eid_photo_match' => true,
            'eid_checked_by' => $checkedBy,
            'eid_checked_at' => $checkedAt,
        ]);

        flash('success', 'eID-identiteitscontrole geregistreerd.');
        redirect('reservation.php?id=' . $id . '#identiteitscontrole');
    }

    if ($action === 'update-total-price') {
        $newTotalPrice = round((float) ($_POST['total_price'] ?? 0), 2);
        $summary = reservation_payment_summary($id, (float) $reservation['total_price']);

        if ($newTotalPrice < 0) {
            flash('error', 'De totaalprijs kan niet negatief zijn.');
            redirect('reservation.php?id=' . $id . '#betalingen');
        }
        if (!empty($contract['signed_at'])) {
            flash('error', 'De totaalprijs kan niet meer worden gewijzigd nadat het contract ondertekend is.');
            redirect('reservation.php?id=' . $id . '#betalingen');
        }
        if ($newTotalPrice + 0.009 < (float) $summary['paid']) {
            flash('error', 'De totaalprijs kan niet lager zijn dan het reeds betaalde bedrag.');
            redirect('reservation.php?id=' . $id . '#betalingen');
        }

        db()->beginTransaction();
        try {
            $stmt = db()->prepare('UPDATE reservations SET total_price = :total_price, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $stmt->execute([
                ':total_price' => $newTotalPrice,
                ':id' => $id,
            ]);

            if ($contract && empty($contract['signed_at'])) {
                $stmt = db()->prepare('DELETE FROM rental_contracts WHERE id = :id');
                $stmt->execute([':id' => (int) $contract['id']]);
                unset($_SESSION['contract_tokens'][(int) $contract['id']]);
            }

            db()->commit();
        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            flash('error', 'De totaalprijs kon niet worden opgeslagen.');
            redirect('reservation.php?id=' . $id . '#betalingen');
        }

        audit('update_total_price', 'reservation', $id, [
            'old_total_price' => (float) $reservation['total_price'],
            'new_total_price' => $newTotalPrice,
            'unsigned_contract_reset' => $contract && empty($contract['signed_at']),
        ]);
        flash('success', 'Totaalprijs bijgewerkt. Een niet-ondertekend contract wordt opnieuw opgebouwd met de nieuwe prijs.');
        redirect('reservation.php?id=' . $id . '#betalingen');
    }

    if ($action === 'add-payment') {
        $amount = round((float) ($_POST['amount'] ?? 0), 2);
        $method = (string) ($_POST['method'] ?? '');
        $note = trim((string) ($_POST['note'] ?? '')) ?: null;
        $summary = reservation_payment_summary($id, (float) $reservation['total_price']);

        if ($amount <= 0 || !in_array($method, ['bancontact', 'cash'], true)) {
            flash('error', 'Vul een positief bedrag in en kies Bancontact of cash.');
            redirect('reservation.php?id=' . $id . '#betalingen');
        }
        if ((float) $reservation['total_price'] <= 0) {
            flash('error', 'Stel eerst de totaalprijs in voordat je een betaling registreert.');
            redirect('reservation.php?id=' . $id . '#betalingen');
        }
        if ($amount - (float) $summary['outstanding'] > 0.009) {
            flash('error', 'Het bedrag is hoger dan het openstaande saldo.');
            redirect('reservation.php?id=' . $id . '#betalingen');
        }

        $stmt = db()->prepare(
            'INSERT INTO payment_logs (reservation_id, amount, method, note, paid_at, recorded_by)
             VALUES (:reservation_id, :amount, :method, :note, CURRENT_TIMESTAMP, :recorded_by)'
        );
        $stmt->execute([
            ':reservation_id' => $id,
            ':amount' => $amount,
            ':method' => $method,
            ':note' => $note,
            ':recorded_by' => (int) current_user()['id'],
        ]);
        $paymentId = (int) db()->lastInsertId();
        audit('create', 'payment_log', $paymentId, [
            'reservation_id' => $id,
            'amount' => $amount,
            'method' => $method,
        ]);
        flash('success', 'Betaling geregistreerd in het betalingslog.');
        redirect('reservation.php?id=' . $id . '#betalingen');
    }
}

$reservation = find_reservation($id) ?? $reservation;
$contract = find_contract_by_reservation($id);
$payments = reservation_payments($id);
$paymentSummary = reservation_payment_summary($id, (float) $reservation['total_price']);

render_header('Verhuur #' . $id);
?>
<section class="grid">
    <div class="card col-8">
        <div class="actions actions-between">
            <div>
                <h2><?= e((string) $reservation['bike_summary']) ?></h2>
                <p class="muted"><?= count($reservation['bikes']) ?> fiets(en) in dit dossier</p>
            </div>
            <div class="actions">
                <a class="button button-secondary" href="#betalingen">Betaling registreren</a>
                <span class="badge status-<?= e((string) $reservation['status']) ?>"><?= e(status_label((string) $reservation['status'])) ?></span>
            </div>
        </div>

        <div class="reservation-bike-list">
            <?php foreach ($reservation['bikes'] as $bike): ?>
                <article class="reservation-bike-item">
                    <div class="reservation-bike-thumb">
                        <?php if (!empty($bike['photo_stored_name'])): ?>
                            <img src="bike-photo.php?id=<?= (int) $bike['id'] ?>&amp;v=<?= e((string) $bike['updated_at']) ?>" alt="<?= e((string) $bike['name']) ?>">
                        <?php else: ?><span>Geen foto</span><?php endif; ?>
                    </div>
                    <div>
                        <strong><?= e((string) $bike['code']) ?> — <?= e((string) $bike['name']) ?></strong>
                        <div class="muted"><?= e((string) $bike['category']) ?> · maat <?= e((string) ($bike['frame_size'] ?: '—')) ?></div>
                        <div class="muted">Framenummer: <?= e((string) ($bike['frame_number'] ?: '—')) ?></div>
                    </div>
                    <span class="badge badge-<?= e((string) $bike['status']) ?>"><?= e(bike_status_label((string) $bike['status'])) ?></span>
                </article>
            <?php endforeach; ?>
        </div>

        <dl class="summary-list mt-18">
            <dt>Periode</dt><dd><?= e((new DateTimeImmutable((string) $reservation['start_at']))->format('d/m/Y H:i')) ?> → <?= e((new DateTimeImmutable((string) $reservation['end_at']))->format('d/m/Y H:i')) ?></dd>
            <dt>Klant</dt><dd><?= e((string) $reservation['customer_name']) ?></dd>
            <dt>Telefoon</dt><dd><?= e((string) ($reservation['customer_phone'] ?: '—')) ?></dd>
            <dt>E-mail</dt><dd><?= e((string) ($reservation['customer_email'] ?: '—')) ?></dd>
            <dt>Adres</dt><dd><?= e((string) ($reservation['customer_address'] ?: '—')) ?></dd>
            <dt>Totaalprijs</dt><dd>€ <?= number_format((float) $reservation['total_price'], 2, ',', '.') ?></dd>
            <dt>Notities</dt><dd><?= nl2br(e((string) ($reservation['notes'] ?: '—'))) ?></dd>
        </dl>
    </div>

    <aside class="card col-4">
        <h2>Huurovereenkomst</h2>
        <?php if (!$contract): ?>
            <p class="muted">Nog geen contract opgemaakt.</p>
            <a class="button" href="contract.php?reservation_id=<?= $id ?>">Gezamenlijk contract opmaken</a>
        <?php elseif (!empty($contract['signed_at'])): ?>
            <p><span class="badge status-confirmed">Ondertekend</span></p>
            <p class="muted">Door <?= e((string) $contract['signer_name']) ?> op <?= e((new DateTimeImmutable((string) $contract['signed_at']))->format('d/m/Y H:i')) ?>.</p>
            <a class="button" href="contract.php?reservation_id=<?= $id ?>">Contract bekijken</a>
        <?php else: ?>
            <p><span class="badge status-reserved">Wacht op handtekening</span></p>
            <a class="button" href="contract.php?reservation_id=<?= $id ?>">Naar ondertekening</a>
        <?php endif; ?>

        <hr><h2 id="identiteitscontrole">eID-identiteitscontrole</h2>
        <?php if (!empty($reservation['eid_checked_at'])): ?>
            <div class="alert alert-success">
                <strong>Identiteit gecontroleerd</strong><br>
                Fysieke eID gecontroleerd: âœ“<br>
                Foto visueel overeenkomstig: âœ“<br>
                Medewerker: <?= e((string) ($reservation['eid_checked_by_name'] ?: 'Onbekend')) ?><br>
                Tijdstip: <?= e((new DateTimeImmutable((string) $reservation['eid_checked_at']))->format('d/m/Y H:i')) ?>
            </div>
        <?php else: ?>
            <p class="muted">Nog niet bevestigd. Voer deze controle uit wanneer de huurder fysiek aanwezig is.</p>
            <form method="post" class="stack">
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="action" value="confirm-eid-check">

                <label class="checkbox-row">
                    <input type="checkbox" name="eid_physical_checked" value="1" required>
                    Fysieke Belgische eID gecontroleerd
                </label>

                <label class="checkbox-row">
                    <input type="checkbox" name="eid_photo_match" value="1" required>
                    Foto op de fysieke eID visueel overeenkomstig met de huurder
                </label>

                <span class="help">
                    Bij bevestiging worden medewerker en tijdstip automatisch geregistreerd.
                    Er wordt geen rijksregisternummer of eID-foto opgeslagen.
                </span>

                <button class="button button-secondary" type="submit">Identiteitscontrole bevestigen</button>
            </form>
        <?php endif; ?>

        <hr><h2>Identiteitsdocument</h2>
        <?php if ($reservation['document_id'] && !$reservation['document_deleted_at']): ?>
            <p><strong><?= e((string) $reservation['document_name']) ?></strong><br><span class="muted"><?= e((string) $reservation['document_mime']) ?> · <?= number_format((int) $reservation['document_size'] / 1024, 0, ',', '.') ?> KB</span></p>
            <p class="muted">Bewaren tot <?= e($reservation['retention_until'] ? (new DateTimeImmutable((string) $reservation['retention_until']))->format('d/m/Y') : 'niet ingesteld') ?></p>
            <a class="button button-secondary" href="index.php?route=id-download&amp;id=<?= (int) $reservation['document_id'] ?>">Veilig openen</a>
        <?php else: ?>
            <p class="muted">Geen document gekoppeld.</p>
        <?php endif; ?>
    </aside>

    <div class="card col-12 payment-card" id="betalingen">
        <div class="actions actions-between">
            <div>
                <h2>Betalingen registreren</h2>
                <p class="muted">Log iedere betaling rechtstreeks op deze reservatie, met betaalwijze, medewerker, datum en uur.</p>
            </div>
            <?php if ($paymentSummary['is_paid']): ?>
                <span class="payment-state payment-paid">Volledig afgerekend</span>
            <?php elseif ($paymentSummary['is_partial']): ?>
                <span class="payment-state payment-partial">Deels betaald</span>
            <?php else: ?>
                <span class="payment-state payment-open">Nog niet betaald</span>
            <?php endif; ?>
        </div>

        <div class="payment-summary-grid">
            <div><span>Totaal</span><strong>€ <?= number_format((float) $reservation['total_price'], 2, ',', '.') ?></strong></div>
            <div><span>Betaald</span><strong>€ <?= number_format((float) $paymentSummary['paid'], 2, ',', '.') ?></strong></div>
            <div><span>Openstaand</span><strong>€ <?= number_format((float) $paymentSummary['outstanding'], 2, ',', '.') ?></strong></div>
        </div>

        <?php if (empty($contract['signed_at'])): ?>
            <form method="post" class="payment-price-form mt-18">
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="action" value="update-total-price">
                <div class="field">
                    <label>Totaalprijs reservatie</label>
                    <input name="total_price" type="number" min="<?= e(number_format((float) $paymentSummary['paid'], 2, '.', '')) ?>" step="0.01" value="<?= e(number_format((float) $reservation['total_price'], 2, '.', '')) ?>" required>
                </div>
                <button class="button button-secondary" type="submit">Totaalprijs opslaan</button>
                <span class="help">Een bestaand maar nog niet ondertekend contract wordt opnieuw opgebouwd met de nieuwe prijs.</span>
            </form>
        <?php endif; ?>

        <?php if (!$paymentSummary['is_paid'] && (float) $reservation['total_price'] > 0): ?>
            <form method="post" class="payment-entry-form mt-18">
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="action" value="add-payment">
                <div class="field"><label>Te registreren bedrag</label><input name="amount" type="number" min="0.01" max="<?= e(number_format((float) $paymentSummary['outstanding'], 2, '.', '')) ?>" step="0.01" value="<?= e(number_format((float) $paymentSummary['outstanding'], 2, '.', '')) ?>" required></div>
                <div class="field"><label>Betaalwijze</label><select name="method" required><option value="bancontact">Bancontact</option><option value="cash">Cash</option></select></div>
                <div class="field"><label>Notitie</label><input name="note" placeholder="Bijvoorbeeld voorschot of restbetaling"></div>
                <button class="button" type="submit">Betaling registreren</button>
            </form>
        <?php elseif ((float) $reservation['total_price'] <= 0): ?>
            <div class="alert alert-warning mt-18">Stel hierboven eerst de totaalprijs in om een betaling te kunnen registreren.</div>
        <?php else: ?>
            <div class="alert alert-success mt-18">Deze reservatie is volledig afgerekend. Nieuwe betalingen zijn geblokkeerd om dubbel registreren te voorkomen.</div>
        <?php endif; ?>

        <h3 class="mt-18">Betalingshistoriek</h3>
        <?php if ($payments): ?>
            <div class="table-wrap"><table>
                <thead><tr><th>Datum</th><th>Bedrag</th><th>Betaalwijze</th><th>Geregistreerd door</th><th>Notitie</th></tr></thead>
                <tbody><?php foreach ($payments as $payment): ?>
                    <tr>
                        <td><?= e((new DateTimeImmutable((string) $payment['paid_at']))->format('d/m/Y H:i')) ?></td>
                        <td><strong>€ <?= number_format((float) $payment['amount'], 2, ',', '.') ?></strong></td>
                        <td><?= e(payment_method_label((string) $payment['method'])) ?></td>
                        <td><?= e((string) ($payment['recorded_by_name'] ?: 'Onbekend')) ?></td>
                        <td><?= e((string) ($payment['note'] ?: '—')) ?></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php else: ?>
            <div class="alert alert-warning">Nog geen betaling geregistreerd.</div>
        <?php endif; ?>
    </div>

    <?php require __DIR__ . '/reservation-stamp.php'; ?>

    <div class="card col-12">
        <h2>Status wijzigen</h2>
        <form method="post" action="index.php?route=reservation-status&amp;id=<?= $id ?>" class="actions">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <select class="select-narrow" name="status"><?php foreach (['reserved', 'confirmed', 'picked_up', 'returned', 'cancelled'] as $status): ?><option value="<?= e($status) ?>" <?= $reservation['status'] === $status ? 'selected' : '' ?>><?= e(status_label($status)) ?></option><?php endforeach; ?></select>
            <button class="button">Status opslaan</button>
            <a class="button button-secondary" href="planning.php?start=<?= e((new DateTimeImmutable((string) $reservation['start_at']))->format('Y-m-d')) ?>">Toon in planning</a>
        </form>
    </div>
</section>
<?php render_footer();

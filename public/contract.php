<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_auth();

$reservationId = (int) ($_GET['reservation_id'] ?? $_POST['reservation_id'] ?? 0);
$reservation = contract_reservation_data($reservationId);
if (!$reservation) {
    http_response_code(404);
    exit('Verhuur niet gevonden.');
}

$contract = find_contract_by_reservation($reservationId);
if (!$contract) {
    try {
        $contract = create_contract_for_reservation($reservationId);
        flash('success', 'Gezamenlijk contract is opgemaakt en klaar voor ondertekening.');
    } catch (Throwable $e) {
        flash('error', $e instanceof RuntimeException ? $e->getMessage() : 'Contract opmaken is mislukt.');
        redirect('reservation.php?id=' . $reservationId);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'new-token' && empty($contract['signed_at'])) {
        contract_issue_token((int) $contract['id']);
        flash('success', 'Er is een nieuwe ondertekenlink aangemaakt.');
        redirect('contract.php?reservation_id=' . $reservationId);
    }

    if ($action === 'resend' && !empty($contract['signed_at'])) {
        $result = send_signed_contract_email($contract, $reservation);
        update_contract_email_result((int) $contract['id'], $result);
        flash($result['status'] === 'sent' ? 'success' : 'warning', contract_email_status_message($result['status'], $result['error']));
        redirect('contract.php?reservation_id=' . $reservationId);
    }
}

if (isset($_GET['download']) && !empty($contract['signed_at'])) {
    $pdfPath = contract_pdf_path($contract);
    if ($pdfPath !== null) {
        audit('download', 'rental_contract', (int) $contract['id'], ['format' => 'pdf']);
        header('Content-Type: application/pdf');
        header('Content-Length: ' . filesize($pdfPath));
        header('Content-Disposition: attachment; filename="' . rawurlencode((string) $contract['contract_number'] . '.pdf') . '"');
        header('Cache-Control: no-store, private');
        readfile($pdfPath);
        exit;
    }

    audit('download', 'rental_contract', (int) $contract['id'], ['format' => 'html']);
    header('Content-Type: text/html; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . rawurlencode((string) $contract['contract_number'] . '.html') . '"');
    echo (string) $contract['signed_contract_html'];
    exit;
}

$contract = find_contract_by_id((int) $contract['id']) ?? $contract;
$token = contract_token_for_staff($contract);
$signUrl = $token ? rtrim(env('APP_URL', 'http://localhost:8080'), '/') . '/sign.php?token=' . rawurlencode($token) : null;

render_header('Contract ' . (string) $contract['contract_number']);
?>
<section class="grid">
    <div class="card col-8">
        <div class="actions actions-between">
            <div>
                <h2><?= e((string) $reservation['bike_summary']) ?></h2>
                <p class="muted"><?= count($reservation['bikes']) ?> fiets(en) · <?= e((string) $reservation['customer_name']) ?> · <?= e((string) $reservation['customer_email']) ?></p>
            </div>
            <?php if (!empty($contract['signed_at'])): ?>
                <span class="badge status-confirmed">Ondertekend</span>
            <?php else: ?>
                <span class="badge status-reserved">Wacht op handtekening</span>
            <?php endif; ?>
        </div>

        <article class="contract-preview admin-contract-preview">
            <?= contract_body_html((string) (!empty($contract['signed_contract_html']) ? $contract['signed_contract_html'] : $contract['contract_html'])) ?>
        </article>
    </div>

    <aside class="card col-4 contract-actions-card">
        <?php if (empty($contract['signed_at'])): ?>
            <h2>Laat de klant tekenen</h2>
            <p>Open de beveiligde ondertekenpagina op deze computer, tablet of telefoon.</p>
            <a class="button button-large" href="<?= e((string) $signUrl) ?>" target="_blank" rel="noopener">Ondertekenpagina openen</a>
            <div class="field mt-18">
                <label>Ondertekenlink</label>
                <input value="<?= e((string) $signUrl) ?>" readonly onclick="this.select()">
                <span class="help">De link bevat een willekeurige toegangscode. Deel hem alleen met de huurder.</span>
            </div>
            <form method="post" class="mt-18">
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="reservation_id" value="<?= $reservationId ?>">
                <input type="hidden" name="action" value="new-token">
                <button class="button button-secondary" type="submit">Nieuwe link maken</button>
            </form>
        <?php else: ?>
            <h2>Ondertekening</h2>
            <dl class="summary-list summary-list-compact">
                <dt>Ondertekenaar</dt><dd><?= e((string) $contract['signer_name']) ?></dd>
                <dt>Datum</dt><dd><?= e((new DateTimeImmutable((string) $contract['signed_at']))->format('d/m/Y H:i')) ?></dd>
                <dt>Contracthash</dt><dd class="hash-value"><?= e((string) $contract['signed_hash']) ?></dd>
                <dt>E-mail</dt><dd><?= e(contract_email_status_message((string) $contract['email_status'], $contract['email_error'])) ?></dd>
            </dl>
            <div class="stack-actions mt-18">
                <a class="button" href="contract.php?reservation_id=<?= $reservationId ?>&amp;download=1">Contract downloaden</a>
                <form method="post">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="reservation_id" value="<?= $reservationId ?>">
                    <input type="hidden" name="action" value="resend">
                    <button class="button button-secondary" type="submit">Contract opnieuw mailen</button>
                </form>
            </div>
        <?php endif; ?>

        <hr>
        <a href="reservation.php?id=<?= $reservationId ?>">← Terug naar verhuur</a>
    </aside>
</section>
<?php render_footer();

function update_contract_email_result(int $contractId, array $result): void
{
    $stmt = db()->prepare(
        'UPDATE rental_contracts
         SET email_status = :status,
             email_sent_at = :sent_at,
             email_error = :error,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );
    $stmt->execute([
        ':status' => $result['status'],
        ':sent_at' => $result['status'] === 'sent' ? (new DateTimeImmutable('now'))->format('Y-m-d H:i:s') : null,
        ':error' => $result['error'],
        ':id' => $contractId,
    ]);
    audit('email', 'rental_contract', $contractId, ['status' => $result['status']]);
}

function contract_email_status_message(string $status, ?string $error): string
{
    return match ($status) {
        'sent' => 'Verzonden',
        'logged' => 'Testmail lokaal opgeslagen',
        'failed' => 'Niet verzonden' . ($error ? ': ' . $error : ''),
        default => 'Nog niet verzonden',
    };
}

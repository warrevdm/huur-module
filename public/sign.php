<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$contract = find_contract_by_token($token);

if (!$contract) {
    http_response_code(404);
    render_public_contract_header('Ondertekenlink ongeldig');
    echo '<div class="contract-shell"><div class="alert alert-error">Deze ondertekenlink is ongeldig of niet meer beschikbaar.</div></div>';
    render_public_contract_footer();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($contract['signed_at'])) {
    verify_csrf();

    if (($_POST['accept_contract'] ?? '') !== '1') {
        flash('error', 'Bevestig dat u de overeenkomst gelezen heeft en ermee akkoord gaat.');
        redirect('sign.php?token=' . rawurlencode($token));
    }

    try {
        $contract = sign_contract(
            $contract,
            (string) ($_POST['signer_name'] ?? ''),
            (string) ($_POST['signature_data'] ?? '')
        );
    } catch (Throwable $e) {
        flash('error', $e instanceof RuntimeException ? $e->getMessage() : 'Ondertekenen is mislukt.');
        redirect('sign.php?token=' . rawurlencode($token));
    }
}

render_public_contract_header(empty($contract['signed_at']) ? 'Huurovereenkomst ondertekenen' : 'Contract ondertekend');
$flashes = take_flashes();
?>
<div class="contract-shell">
    <?php foreach ($flashes as $flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endforeach; ?>

    <?php if (!empty($contract['signed_at'])): ?>
        <div class="alert alert-success">
            <strong>De huurovereenkomst is ondertekend.</strong><br>
            <?php if (($contract['email_status'] ?? '') === 'sent'): ?>
                Een kopie werd verzonden naar het opgegeven e-mailadres.
            <?php elseif (($contract['email_status'] ?? '') === 'logged'): ?>
                De module staat nog in testmodus. De contractmail is lokaal opgeslagen en nog niet echt verzonden.
            <?php else: ?>
                De ondertekening is opgeslagen, maar de e-mail kon niet worden verzonden. Aerts Action Bike kan de contractkopie opnieuw versturen.
            <?php endif; ?>
        </div>
        <article class="contract-preview signed-contract">
            <?= contract_body_html((string) $contract['signed_contract_html']) ?>
        </article>
    <?php else: ?>
        <div class="contract-intro">
            <h1>Controleer en onderteken uw huurovereenkomst</h1>
            <p>Lees de volledige overeenkomst. Onderteken pas wanneer de fiets, periode, prijs en voorwaarden correct zijn.</p>
        </div>

        <article class="contract-preview">
            <?= contract_body_html((string) $contract['contract_html']) ?>
        </article>

        <form method="post" class="signature-form" id="signature-form">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <input type="hidden" name="signature_data" id="signature-data">

            <div class="field">
                <label for="signer-name">Volledige naam ondertekenaar *</label>
                <input id="signer-name" name="signer_name" required maxlength="150" autocomplete="name">
            </div>

            <div class="field">
                <label>Handtekening *</label>
                <div class="signature-pad-wrap">
                    <canvas id="signature-canvas" width="900" height="260" aria-label="Teken hier uw handtekening"></canvas>
                </div>
                <div class="actions">
                    <button class="button button-secondary" id="signature-clear" type="button">Handtekening wissen</button>
                </div>
            </div>

            <label class="accept-row">
                <input class="checkbox-inline" type="checkbox" name="accept_contract" value="1" required>
                <span>Ik heb de volledige huurovereenkomst gelezen, de gegevens gecontroleerd en ga akkoord met de voorwaarden.</span>
            </label>

            <div class="alert alert-warning" id="signature-error" hidden></div>
            <button class="button button-large" type="submit">Definitief ondertekenen</button>
        </form>
    <?php endif; ?>
</div>
<?php
render_public_contract_footer();

function render_public_contract_header(string $title): void
{
    ?>
    <!doctype html>
    <html lang="nl-BE">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= e($title) ?> · Aerts Action Bike</title>
        <link rel="stylesheet" href="assets/styles.css">
        <link rel="stylesheet" href="assets/contract.css">
    </head>
    <body class="contract-page">
    <header class="public-contract-header">
        <strong>Aerts Action Bike</strong>
        <span>Kapellensteenweg 394 · 2920 Kalmthout</span>
    </header>
    <?php
}

function render_public_contract_footer(): void
{
    ?>
    <script src="assets/signature.js" defer></script>
    </body>
    </html>
    <?php
}

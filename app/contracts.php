<?php

declare(strict_types=1);

function contract_reservation_data(int $reservationId): ?array
{
    $stmt = db()->prepare(
        "SELECT r.*, b.code AS bike_code, b.name AS bike_name, b.category AS bike_category,
                b.frame_size AS bike_frame_size, b.daily_rate,
                c.name AS customer_name, c.email AS customer_email, c.phone AS customer_phone,
                c.address AS customer_address
         FROM reservations r
         JOIN bikes b ON b.id = r.bike_id
         JOIN customers c ON c.id = r.customer_id
         WHERE r.id = :id
         LIMIT 1"
    );
    $stmt->execute([':id' => $reservationId]);
    return $stmt->fetch() ?: null;
}

function find_contract_by_reservation(int $reservationId): ?array
{
    $stmt = db()->prepare('SELECT * FROM rental_contracts WHERE reservation_id = :reservation_id LIMIT 1');
    $stmt->execute([':reservation_id' => $reservationId]);
    return $stmt->fetch() ?: null;
}

function find_contract_by_id(int $contractId): ?array
{
    $stmt = db()->prepare('SELECT * FROM rental_contracts WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $contractId]);
    return $stmt->fetch() ?: null;
}

function find_contract_by_token(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM rental_contracts WHERE public_token_hash = :token_hash LIMIT 1');
    $stmt->execute([':token_hash' => hash('sha256', $token)]);
    return $stmt->fetch() ?: null;
}

function contract_number(int $reservationId): string
{
    return 'AAB-' . date('Y') . '-' . str_pad((string) $reservationId, 6, '0', STR_PAD_LEFT);
}

function contract_issue_token(int $contractId): string
{
    $token = bin2hex(random_bytes(32));
    $stmt = db()->prepare(
        'UPDATE rental_contracts
         SET public_token_hash = :token_hash, updated_at = CURRENT_TIMESTAMP
         WHERE id = :id AND signed_at IS NULL'
    );
    $stmt->execute([
        ':token_hash' => hash('sha256', $token),
        ':id' => $contractId,
    ]);
    $_SESSION['contract_tokens'][$contractId] = $token;
    return $token;
}

function contract_token_for_staff(array $contract): ?string
{
    if (!empty($contract['signed_at'])) {
        return null;
    }

    $contractId = (int) $contract['id'];
    $token = $_SESSION['contract_tokens'][$contractId] ?? null;
    if (is_string($token) && preg_match('/^[a-f0-9]{64}$/', $token)) {
        return $token;
    }

    return contract_issue_token($contractId);
}

function create_contract_for_reservation(int $reservationId): array
{
    $existing = find_contract_by_reservation($reservationId);
    if ($existing) {
        return $existing;
    }

    $reservation = contract_reservation_data($reservationId);
    if (!$reservation) {
        throw new RuntimeException('Verhuur niet gevonden.');
    }
    if (empty($reservation['customer_email']) || !filter_var($reservation['customer_email'], FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Vul eerst een geldig e-mailadres van de klant in.');
    }

    $number = contract_number($reservationId);
    $unsignedHtml = render_contract_document($reservation, $number, null);
    $contractHash = hash('sha256', $unsignedHtml);
    $token = bin2hex(random_bytes(32));

    $stmt = db()->prepare(
        'INSERT INTO rental_contracts
         (reservation_id, contract_number, public_token_hash, contract_html, contract_hash, created_at, updated_at)
         VALUES (:reservation_id, :contract_number, :token_hash, :contract_html, :contract_hash, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
    );
    $stmt->execute([
        ':reservation_id' => $reservationId,
        ':contract_number' => $number,
        ':token_hash' => hash('sha256', $token),
        ':contract_html' => $unsignedHtml,
        ':contract_hash' => $contractHash,
    ]);

    $contractId = (int) db()->lastInsertId();
    $_SESSION['contract_tokens'][$contractId] = $token;
    audit('create', 'rental_contract', $contractId, ['reservation_id' => $reservationId]);

    return find_contract_by_id($contractId) ?? throw new RuntimeException('Contract kon niet worden geladen.');
}

function contract_company_details(): array
{
    return [
        'name' => env('COMPANY_NAME', 'Aerts Action Bike'),
        'address' => env('COMPANY_ADDRESS', 'Kapellensteenweg 394, 2920 Kalmthout'),
        'email' => env('COMPANY_EMAIL', 'info@aertsactionbike.be'),
        'phone' => env('COMPANY_PHONE', ''),
        'vat' => env('COMPANY_VAT', ''),
    ];
}

function render_contract_document(array $reservation, string $number, ?array $signature): string
{
    $company = contract_company_details();
    $start = new DateTimeImmutable((string) $reservation['start_at']);
    $end = new DateTimeImmutable((string) $reservation['end_at']);
    $dailyRate = (float) $reservation['daily_rate'];
    $totalPrice = (float) $reservation['total_price'];
    $lateFee = $dailyRate > 0 ? '€ ' . number_format($dailyRate, 2, ',', '.') : 'de op dat moment geldende daghuur';
    $signedBlock = '';

    if ($signature) {
        $signedAt = new DateTimeImmutable((string) $signature['signed_at']);
        $signedBlock = '<div class="signature-box">'
            . '<p><strong>Ondertekend door:</strong> ' . e((string) $signature['signer_name']) . '</p>'
            . '<img src="' . e((string) $signature['signature_data_uri']) . '" alt="Elektronische handtekening">'
            . '<p>Ondertekend op ' . e($signedAt->format('d/m/Y \o\m H:i')) . ' (Europe/Brussels)</p>'
            . '<p class="small">Bewijsreferentie: ' . e((string) $signature['signed_hash']) . '</p>'
            . '</div>';
    } else {
        $signedBlock = '<div class="signature-box"><p><strong>Elektronische ondertekening volgt via de ondertekenpagina.</strong></p></div>';
    }

    $companyExtra = '';
    if ($company['phone'] !== '') {
        $companyExtra .= ' · ' . e($company['phone']);
    }
    if ($company['vat'] !== '') {
        $companyExtra .= ' · ' . e($company['vat']);
    }

    return '<!doctype html><html lang="nl-BE"><head><meta charset="utf-8">'
        . '<style>'
        . '@page{margin:28mm 20mm}body{font-family:DejaVu Sans,Arial,sans-serif;color:#17211a;font-size:10.5pt;line-height:1.45}'
        . 'h1{font-size:21pt;margin:0 0 4px}h2{font-size:13pt;margin:20px 0 7px;border-bottom:1px solid #dce4dd;padding-bottom:4px}'
        . '.brand{color:#31852c;font-weight:700}.meta{color:#657167;font-size:9pt}.grid{width:100%;border-collapse:collapse;margin:8px 0}'
        . '.grid th,.grid td{border:1px solid #dce4dd;padding:7px;text-align:left;vertical-align:top}.grid th{width:30%;background:#f5f8f5}'
        . 'ol{padding-left:20px}li{margin-bottom:7px}.signature-box{margin-top:22px;border:1px solid #8ca08f;border-radius:8px;padding:14px;background:#f8fbf8}'
        . '.signature-box img{max-width:320px;max-height:120px;display:block;margin:10px 0}.small{font-size:8pt;color:#657167;word-break:break-all}'
        . '.notice{padding:10px;border-left:4px solid #60bb46;background:#f1f8ef}'
        . '</style></head><body>'
        . '<div class="brand">' . e((string) $company['name']) . '</div>'
        . '<div class="meta">' . e((string) $company['address']) . ' · ' . e((string) $company['email']) . $companyExtra . '</div>'
        . '<h1>Huurovereenkomst fiets</h1>'
        . '<p class="meta">Contractnummer ' . e($number) . '</p>'
        . '<h2>1. Partijen en reservatie</h2>'
        . '<table class="grid">'
        . '<tr><th>Verhuurder</th><td>' . e((string) $company['name']) . ', ' . e((string) $company['address']) . '</td></tr>'
        . '<tr><th>Huurder</th><td>' . e((string) $reservation['customer_name']) . '<br>' . e((string) ($reservation['customer_address'] ?: 'Adres niet opgegeven')) . '<br>' . e((string) ($reservation['customer_email'] ?: '')) . ' · ' . e((string) ($reservation['customer_phone'] ?: '')) . '</td></tr>'
        . '<tr><th>Fiets</th><td>' . e((string) $reservation['bike_code']) . ' — ' . e((string) $reservation['bike_name']) . ' (' . e((string) $reservation['bike_category']) . ')' . ($reservation['bike_frame_size'] ? ', maat ' . e((string) $reservation['bike_frame_size']) : '') . '</td></tr>'
        . '<tr><th>Huurperiode</th><td>' . e($start->format('d/m/Y H:i')) . ' tot ' . e($end->format('d/m/Y H:i')) . '</td></tr>'
        . '<tr><th>Prijs</th><td>Totale huurprijs: € ' . number_format($totalPrice, 2, ',', '.') . '<br>Daghuur: € ' . number_format($dailyRate, 2, ',', '.') . '</td></tr>'
        . '</table>'
        . '<h2>2. Staat, gebruik en veiligheid</h2><ol>'
        . '<li>De verhuurder levert de fiets in een veilige, bruikbare en bij afhaling gezamenlijk controleerbare staat. Zichtbare opmerkingen worden vóór vertrek genoteerd.</li>'
        . '<li>De huurder gebruikt de fiets zorgvuldig, overeenkomstig de bestemming, de verkeersregels en eventuele mondelinge of schriftelijke gebruiksinstructies. Onderverhuur of terbeschikkingstelling aan derden is niet toegestaan zonder voorafgaand akkoord.</li>'
        . '<li>De huurder beveiligt de fiets bij elke onbeheerde stalling met het meegeleverde of een gelijkwaardig degelijk slot. Sleutels, lader en meegeleverde accessoires blijven bij de fiets.</li>'
        . '<li>Normale slijtage is voor rekening van de verhuurder. Schade door foutief, roekeloos of onzorgvuldig gebruik wordt aangerekend op basis van de aantoonbare herstelkost.</li>'
        . '</ol>'
        . '<h2>3. Teruggave, laattijdigheid en niet-teruggave</h2><ol>'
        . '<li>De fiets moet uiterlijk op het afgesproken eindmoment en op de afgesproken plaats worden teruggebracht, tenzij de verhuurder vooraf schriftelijk een verlenging bevestigt.</li>'
        . '<li>Bij laattijdige teruggave zonder voorafgaand akkoord is per begonnen periode van 24 uur een bijkomende gebruiksvergoeding verschuldigd van ' . e($lateFee) . '. Een hogere vergoeding kan alleen worden gevraagd voor aantoonbare bijkomende schade die rechtstreeks door de laattijdigheid is veroorzaakt.</li>'
        . '<li>Wanneer de fiets na een schriftelijke ingebrekestelling niet wordt teruggebracht, is de huurder aansprakelijk voor de aantoonbare actuele vervangingswaarde van de fiets, rekening houdend met leeftijd, staat en normale slijtage, plus redelijke en bewezen recuperatiekosten binnen de wettelijke grenzen.</li>'
        . '<li>Bij diefstal of verlies verwittigt de huurder de verhuurder onmiddellijk en doet de huurder zo snel mogelijk aangifte bij de politie. Het proces-verbaal en alle beschikbare sleutels en accessoires worden aan de verhuurder bezorgd.</li>'
        . '</ol>'
        . '<h2>4. Verplichtingen van de verhuurder</h2><ol>'
        . '<li>Indien de gereserveerde fiets door een oorzaak aan de zijde van de verhuurder niet beschikbaar of niet veilig bruikbaar is, biedt de verhuurder naar keuze van de huurder een gelijkwaardig alternatief zonder toeslag, een nieuwe datum of terugbetaling van het niet-uitgevoerde deel.</li>'
        . '<li>De verhuurder beperkt zijn aansprakelijkheid niet voor schade die voortvloeit uit bedrog, zware fout of een wettelijke verplichting die niet contractueel kan worden uitgesloten.</li>'
        . '</ol>'
        . '<h2>5. Persoonsgegevens en elektronische ondertekening</h2><ol>'
        . '<li>Persoonsgegevens worden verwerkt voor het opmaken, uitvoeren, bewijzen en administreren van deze huurovereenkomst en voor het verzenden van de contractkopie.</li>'
        . '<li>De elektronische ondertekening wordt gekoppeld aan dit contractnummer en de ongewijzigde contractinhoud. Datum, tijdstip, technische bewijsgegevens en een cryptografische hash worden bewaard om de ondertekening te kunnen aantonen.</li>'
        . '<li>De huurder ontvangt na ondertekening een kopie van de ondertekende overeenkomst via het opgegeven e-mailadres.</li>'
        . '</ol>'
        . '<div class="notice"><strong>Akkoordverklaring.</strong> De huurder verklaart de fiets en accessoires te hebben gecontroleerd, de bepalingen te hebben gelezen en ermee akkoord te gaan.</div>'
        . $signedBlock
        . '</body></html>';
}

function contract_body_html(string $document): string
{
    if (preg_match('#<body>(.*)</body>#si', $document, $matches)) {
        return $matches[1];
    }
    return $document;
}

function decode_signature_data_uri(string $dataUri): array
{
    if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dataUri, $matches)) {
        throw new RuntimeException('De handtekening heeft een ongeldig formaat.');
    }

    $binary = base64_decode($matches[1], true);
    if ($binary === false || strlen($binary) < 100 || strlen($binary) > 2_000_000) {
        throw new RuntimeException('De handtekening is leeg of te groot.');
    }
    if (substr($binary, 0, 8) !== "\x89PNG\r\n\x1a\n") {
        throw new RuntimeException('De handtekening is geen geldige PNG-afbeelding.');
    }

    return [$binary, 'data:image/png;base64,' . base64_encode($binary)];
}

function sign_contract(array $contract, string $signerName, string $signatureDataUri): array
{
    if (!empty($contract['signed_at'])) {
        return $contract;
    }

    if (!hash_equals((string) $contract['contract_hash'], hash('sha256', (string) $contract['contract_html']))) {
        throw new RuntimeException('De contractinhoud is gewijzigd en kan niet veilig worden ondertekend. Maak het contract opnieuw op.');
    }

    $signerName = trim($signerName);
    $nameLength = function_exists('mb_strlen') ? mb_strlen($signerName) : strlen($signerName);
    if ($nameLength < 2 || $nameLength > 150) {
        throw new RuntimeException('Vul de volledige naam van de ondertekenaar in.');
    }

    [$signatureBinary, $normalizedDataUri] = decode_signature_data_uri($signatureDataUri);
    $signedAt = new DateTimeImmutable('now');
    $contractId = (int) $contract['id'];
    $signatureDir = ROOT_PATH . '/storage/private/signatures';
    if (!is_dir($signatureDir) && !mkdir($signatureDir, 0770, true) && !is_dir($signatureDir)) {
        throw new RuntimeException('De handtekeningmap kon niet worden aangemaakt.');
    }

    $signatureName = bin2hex(random_bytes(24)) . '.png';
    $signaturePath = $signatureDir . '/' . $signatureName;
    if (file_put_contents($signaturePath, $signatureBinary, LOCK_EX) === false) {
        throw new RuntimeException('De handtekening kon niet veilig worden opgeslagen.');
    }
    @chmod($signaturePath, 0640);

    $reservation = contract_reservation_data((int) $contract['reservation_id']);
    if (!$reservation) {
        @unlink($signaturePath);
        throw new RuntimeException('De verhuurgegevens zijn niet meer beschikbaar.');
    }

    $temporarySignature = [
        'signer_name' => $signerName,
        'signed_at' => $signedAt->format('Y-m-d H:i:s'),
        'signature_data_uri' => $normalizedDataUri,
        'signed_hash' => '',
    ];
    $preHashHtml = render_contract_document($reservation, (string) $contract['contract_number'], $temporarySignature);
    $signedHash = hash('sha256', $preHashHtml . '|' . (string) $contract['contract_hash']);
    $temporarySignature['signed_hash'] = $signedHash;
    $signedHtml = render_contract_document($reservation, (string) $contract['contract_number'], $temporarySignature);

    db()->beginTransaction();
    try {
        $stmt = db()->prepare(
            'UPDATE rental_contracts
             SET signer_name = :signer_name,
                 signature_stored_name = :signature_stored_name,
                 signed_at = :signed_at,
                 signer_ip = :signer_ip,
                 signer_user_agent = :signer_user_agent,
                 signed_contract_html = :signed_contract_html,
                 signed_hash = :signed_hash,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND signed_at IS NULL'
        );
        $stmt->execute([
            ':signer_name' => $signerName,
            ':signature_stored_name' => $signatureName,
            ':signed_at' => $signedAt->format('Y-m-d H:i:s'),
            ':signer_ip' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            ':signer_user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
            ':signed_contract_html' => $signedHtml,
            ':signed_hash' => $signedHash,
            ':id' => $contractId,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Dit contract werd intussen al ondertekend.');
        }
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        @unlink($signaturePath);
        throw $e;
    }

    $updated = find_contract_by_id($contractId) ?? throw new RuntimeException('Ondertekend contract kon niet worden geladen.');
    $pdfStoredName = generate_contract_pdf($updated);
    if ($pdfStoredName !== null) {
        $stmt = db()->prepare('UPDATE rental_contracts SET pdf_stored_name = :pdf, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute([':pdf' => $pdfStoredName, ':id' => $contractId]);
        $updated['pdf_stored_name'] = $pdfStoredName;
    }

    $mailResult = send_signed_contract_email($updated, $reservation);
    $stmt = db()->prepare(
        'UPDATE rental_contracts
         SET email_status = :status,
             email_sent_at = :sent_at,
             email_error = :error,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );
    $stmt->execute([
        ':status' => $mailResult['status'],
        ':sent_at' => $mailResult['status'] === 'sent' ? (new DateTimeImmutable('now'))->format('Y-m-d H:i:s') : null,
        ':error' => $mailResult['error'],
        ':id' => $contractId,
    ]);

    audit('sign', 'rental_contract', $contractId, [
        'reservation_id' => (int) $contract['reservation_id'],
        'email_status' => $mailResult['status'],
        'signed_hash' => $signedHash,
    ]);

    return find_contract_by_id($contractId) ?? $updated;
}

function generate_contract_pdf(array $contract): ?string
{
    if (empty($contract['signed_contract_html']) || !class_exists(\Dompdf\Dompdf::class)) {
        return null;
    }

    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('isHtml5ParserEnabled', true);
    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml((string) $contract['signed_contract_html'], 'UTF-8');
    $dompdf->setPaper('A4');
    $dompdf->render();

    $dir = ROOT_PATH . '/storage/private/contracts';
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        return null;
    }
    $name = preg_replace('/[^A-Za-z0-9_-]/', '-', (string) $contract['contract_number']) . '.pdf';
    $path = $dir . '/' . $name;
    if (file_put_contents($path, $dompdf->output(), LOCK_EX) === false) {
        return null;
    }
    @chmod($path, 0640);
    return $name;
}

function contract_pdf_path(array $contract): ?string
{
    $name = (string) ($contract['pdf_stored_name'] ?? '');
    if ($name === '') {
        return null;
    }
    $path = ROOT_PATH . '/storage/private/contracts/' . basename($name);
    return is_file($path) ? $path : null;
}

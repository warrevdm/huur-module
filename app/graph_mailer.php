<?php

declare(strict_types=1);

function send_signed_contract_graph(array $contract, array $reservation, string $subject): array
{
    $to = trim((string) ($reservation['customer_email'] ?? ''));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['status' => 'failed', 'error' => 'Geen geldig e-mailadres van de klant.'];
    }

    $tenantId = trim((string) env('GRAPH_TENANT_ID', ''));
    $clientId = trim((string) env('GRAPH_CLIENT_ID', ''));
    $clientSecret = (string) env('GRAPH_CLIENT_SECRET', '');
    $from = trim((string) env('GRAPH_FROM_ADDRESS', env('MAIL_FROM_ADDRESS', '')));

    $missing = [];
    if ($tenantId === '') $missing[] = 'GRAPH_TENANT_ID';
    if ($clientId === '') $missing[] = 'GRAPH_CLIENT_ID';
    if ($clientSecret === '') $missing[] = 'GRAPH_CLIENT_SECRET';
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) $missing[] = 'GRAPH_FROM_ADDRESS';

    if ($missing) {
        return [
            'status' => 'failed',
            'error' => 'Microsoft Graph-configuratie ontbreekt of is ongeldig: ' . implode(', ', $missing) . '.',
        ];
    }

    $pdfPath = contract_pdf_path($contract);
    if ($pdfPath === null || !is_file($pdfPath)) {
        return ['status' => 'failed', 'error' => 'Het ondertekende PDF-contract ontbreekt; e-mail niet verzonden.'];
    }

    $pdf = file_get_contents($pdfPath);
    if ($pdf === false || $pdf === '') {
        return ['status' => 'failed', 'error' => 'Het PDF-contract kon niet worden gelezen.'];
    }

    if (strlen($pdf) > 2_500_000) {
        return [
            'status' => 'failed',
            'error' => 'Het PDF-contract is te groot voor de directe Graph-mailbijlage (> 2,5 MB).',
        ];
    }

    $customerName = trim((string) ($reservation['customer_name'] ?? ''));
    $contractNumber = (string) ($contract['contract_number'] ?? 'huurovereenkomst');
    $companyName = (string) env('COMPANY_NAME', 'Aerts Action Bike');
    $companyAddress = (string) env('COMPANY_ADDRESS', 'Kapellensteenweg 394, 2920 Kalmthout');
    $companyEmail = (string) env('COMPANY_EMAIL', 'info@aertsactionbike.be');

    $h = static fn (string $value): string => htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    $greeting = $customerName !== '' ? 'Beste ' . $h($customerName) . ',' : 'Beste,';
    $bodyHtml = '<!doctype html><html lang="nl-BE"><body style="margin:0;background:#f4f6f4;font-family:Arial,Helvetica,sans-serif;color:#17211a">'
        . '<div style="max-width:640px;margin:0 auto;padding:32px 18px">'
        . '<div style="background:#17211a;border-radius:12px 12px 0 0;padding:22px 26px;color:#fff">'
        . '<div style="font-size:20px;font-weight:700">Aerts Action Bike</div>'
        . '<div style="margin-top:5px;color:#cbd5cb;font-size:13px">' . $h($companyAddress) . '</div>'
        . '</div>'
        . '<div style="background:#fff;border-radius:0 0 12px 12px;padding:28px 26px">'
        . '<p style="margin-top:0">' . $greeting . '</p>'
        . '<p>Bedankt voor uw verhuur bij Aerts Action Bike.</p>'
        . '<p>In bijlage vindt u de definitief ondertekende huurovereenkomst <strong>' . $h($contractNumber) . '</strong> als PDF.</p>'
        . '<p>Bewaar dit document als uw contractkopie.</p>'
        . '<p style="margin-bottom:0">Met vriendelijke groeten,<br><strong>' . $h($companyName) . '</strong><br>'
        . '<a href="mailto:' . $h($companyEmail) . '">' . $h($companyEmail) . '</a></p>'
        . '</div></div></body></html>';

    $payload = [
        'message' => [
            'subject' => $subject,
            'body' => [
                'contentType' => 'HTML',
                'content' => $bodyHtml,
            ],
            'toRecipients' => [[
                'emailAddress' => [
                    'address' => $to,
                    'name' => $customerName,
                ],
            ]],
            'replyTo' => [[
                'emailAddress' => [
                    'address' => $from,
                    'name' => (string) env('MAIL_FROM_NAME', $companyName),
                ],
            ]],
            'attachments' => [[
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'name' => $contractNumber . '.pdf',
                'contentType' => 'application/pdf',
                'contentBytes' => base64_encode($pdf),
            ]],
        ],
        'saveToSentItems' => true,
    ];

    try {
        $token = graph_access_token($tenantId, $clientId, $clientSecret);
        $url = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($from) . '/sendMail';

        [$status, $response] = graph_http_request(
            'POST',
            $url,
            [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );

        if ($status === 202) {
            return ['status' => 'sent', 'error' => null];
        }

        return [
            'status' => 'failed',
            'error' => 'Microsoft Graph weigerde de contractmail (HTTP ' . $status . '): '
                . graph_error_message($response),
        ];
    } catch (Throwable $e) {
        return [
            'status' => 'failed',
            'error' => substr('Microsoft Graph: ' . $e->getMessage(), 0, 1000),
        ];
    }
}

function graph_access_token(string $tenantId, string $clientId, string $clientSecret): string
{
    static $cachedToken = null;
    static $expiresAt = 0;

    if (is_string($cachedToken) && $cachedToken !== '' && time() < ($expiresAt - 60)) {
        return $cachedToken;
    }

    $url = 'https://login.microsoftonline.com/' . rawurlencode($tenantId) . '/oauth2/v2.0/token';
    $body = http_build_query([
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'scope' => 'https://graph.microsoft.com/.default',
        'grant_type' => 'client_credentials',
    ], '', '&', PHP_QUERY_RFC3986);

    [$status, $response] = graph_http_request(
        'POST',
        $url,
        [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ],
        $body
    );

    $data = json_decode($response, true);
    if ($status !== 200 || !is_array($data) || empty($data['access_token'])) {
        throw new RuntimeException(
            'Access token kon niet worden opgehaald (HTTP ' . $status . '): ' . graph_error_message($response)
        );
    }

    $cachedToken = (string) $data['access_token'];
    $expiresAt = time() + max(300, (int) ($data['expires_in'] ?? 3600));

    return $cachedToken;
}

/** @return array{0:int,1:string} */
function graph_http_request(string $method, string $url, array $headers, string $body): array
{
    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('cURL kon niet worden gestart.');
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $response = curl_exec($curl);
        if ($response === false) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new RuntimeException('HTTP-verbinding mislukt: ' . $error);
        }

        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        return [$status, (string) $response];
    }

    if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
        throw new RuntimeException('PHP cURL ontbreekt en allow_url_fopen staat uit.');
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'ignore_errors' => true,
            'timeout' => 30,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $response = @file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $status = 0;

    foreach ($responseHeaders as $headerLine) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $headerLine, $matches)) {
            $status = (int) $matches[1];
        }
    }

    if ($response === false && $status === 0) {
        $lastError = error_get_last();
        throw new RuntimeException('HTTP-verbinding mislukt: ' . ($lastError['message'] ?? 'onbekende fout'));
    }

    return [$status, $response === false ? '' : (string) $response];
}

function graph_error_message(string $response): string
{
    $data = json_decode($response, true);
    if (is_array($data)) {
        $message = $data['error']['message']
            ?? $data['error_description']
            ?? $data['error']
            ?? null;

        if (is_string($message) && trim($message) !== '') {
            return substr(trim($message), 0, 700);
        }
    }

    $plain = trim(strip_tags($response));
    return $plain !== '' ? substr($plain, 0, 700) : 'geen foutdetails ontvangen';
}

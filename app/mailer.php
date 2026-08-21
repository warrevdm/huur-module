<?php

declare(strict_types=1);

require_once __DIR__ . '/graph_mailer.php';

function send_signed_contract_email(array $contract, array $reservation): array
{
    $to = trim((string) ($reservation['customer_email'] ?? ''));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['status' => 'failed', 'error' => 'Geen geldig e-mailadres van de klant.'];
    }

    $transport = strtolower(trim(env('MAIL_TRANSPORT', 'log')));
    $subject = 'Uw ondertekende huurovereenkomst ' . (string) $contract['contract_number'];
    $html = (string) ($contract['signed_contract_html'] ?? '');
    $plain = "Beste {$reservation['customer_name']},\n\n"
        . "In bijlage en hieronder vindt u uw ondertekende huurovereenkomst {$contract['contract_number']}.\n"
        . "Bewaar deze e-mail als contractkopie.\n\n"
        . env('COMPANY_NAME', 'Aerts Action Bike') . "\n"
        . env('COMPANY_ADDRESS', 'Kapellensteenweg 394, 2920 Kalmthout') . "\n"
        . env('COMPANY_EMAIL', 'info@aertsactionbike.be');

    if ($transport === 'log') {
        $dir = ROOT_PATH . '/storage/private/mail';
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            return ['status' => 'failed', 'error' => 'De lokale mailmap kon niet worden aangemaakt.'];
        }
        $safeNumber = preg_replace('/[^A-Za-z0-9_-]/', '-', (string) $contract['contract_number']);
        $path = $dir . '/' . $safeNumber . '-' . date('Ymd-His') . '.html';
        $debugHtml = '<!doctype html><html lang="nl"><head><meta charset="utf-8"><title>' . e($subject) . '</title></head><body>'
            . '<p><strong>Aan:</strong> ' . e($to) . '</p><p><strong>Onderwerp:</strong> ' . e($subject) . '</p><hr>'
            . $html . '</body></html>';
        if (file_put_contents($path, $debugHtml, LOCK_EX) === false) {
            return ['status' => 'failed', 'error' => 'De testmail kon niet worden opgeslagen.'];
        }
        @chmod($path, 0640);
        return ['status' => 'logged', 'error' => 'Testmodus: e-mail opgeslagen onder storage/private/mail.'];
    }

    if ($transport === 'graph') {
        return send_signed_contract_graph($contract, $reservation, $subject);
    }

    if ($transport === 'mail') {
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . env('MAIL_FROM_NAME', 'Aerts Action Bike') . ' <' . env('MAIL_FROM_ADDRESS', 'info@aertsactionbike.be') . '>',
        ];
        $sent = mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $html, implode("\r\n", $headers));
        return $sent
            ? ['status' => 'sent', 'error' => null]
            : ['status' => 'failed', 'error' => 'PHP mail() kon de e-mail niet verzenden.'];
    }

    if ($transport !== 'smtp') {
        return ['status' => 'failed', 'error' => 'Onbekend mailtransport: ' . $transport];
    }

    if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        return ['status' => 'failed', 'error' => 'PHPMailer ontbreekt. Voer composer install uit.'];
    }

    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->CharSet = 'UTF-8';
        $mail->isSMTP();
        $mail->Host = env('MAIL_HOST', '');
        $mail->Port = (int) env('MAIL_PORT', '587');
        $mail->SMTPAuth = env('MAIL_USERNAME', '') !== '';
        $mail->Username = env('MAIL_USERNAME', '');
        $mail->Password = env('MAIL_PASSWORD', '');

        $encryption = strtolower(env('MAIL_ENCRYPTION', 'tls'));
        if ($encryption === 'tls') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($encryption === 'ssl' || $encryption === 'smtps') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }

        $fromAddress = env('MAIL_FROM_ADDRESS', env('COMPANY_EMAIL', 'info@aertsactionbike.be'));
        $fromName = env('MAIL_FROM_NAME', env('COMPANY_NAME', 'Aerts Action Bike'));
        $mail->setFrom($fromAddress, $fromName);
        $mail->addAddress($to, (string) $reservation['customer_name']);
        $mail->addReplyTo($fromAddress, $fromName);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $html;
        $mail->AltBody = $plain;

        $pdfPath = contract_pdf_path($contract);
        if ($pdfPath !== null) {
            $mail->addAttachment($pdfPath, (string) $contract['contract_number'] . '.pdf');
        }

        $mail->send();
        return ['status' => 'sent', 'error' => null];
    } catch (Throwable $e) {
        return ['status' => 'failed', 'error' => substr($e->getMessage(), 0, 1000)];
    }
}

<?php
declare(strict_types=1);

require_once __DIR__ . '/email_transport.php';

/**
 * Sends the generated skadeblankett PDF only to the owner-confirmed inbox.
 * The customer address and configured additional recipients are not copied;
 * customer data is never written to the audit log.
 */
function send_skadeblankett_email(string $pdfPath, array $data, string $regnr): array|false
{
    if (!is_string($pdfPath) || !is_array($data) || !is_string($regnr)) {
        return false;
    }

    $pdfRoot = realpath(__DIR__ . '/../pdf');
    $realPdf = realpath($pdfPath);
    if (
        $pdfRoot === false || $realPdf === false || is_link($pdfPath) || !is_file($realPdf) ||
        strtolower(pathinfo($realPdf, PATHINFO_EXTENSION)) !== 'pdf' ||
        strpos($realPdf, rtrim($pdfRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) !== 0 ||
        filesize($realPdf) > 20971520
    ) {
        return false;
    }

    // User-confirmed delivery rule: send skadeblanketter only to the owner's
    // inbox. Do not copy the customer or the portal's additional recipients.
    $settings = function_exists('load_settings') ? load_settings() : [];
    $ownerEmail = is_string($settings['skade_recipient_email'] ?? null) ? trim($settings['skade_recipient_email']) : '';
    if (!filter_var($ownerEmail, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n\x00]/', $ownerEmail)) {
        return ['accepted' => false, 'transport' => 'none', 'code' => 'recipient_not_configured'];
    }
    $companyName = is_string($settings['company_name'] ?? null) ? trim($settings['company_name']) : '';
    $companyName = str_replace(["\r", "\n"], ' ', $companyName);
    $companyName = $companyName !== '' ? $companyName : 'Dokumentportalen';

    $safeRegnr = strtoupper(preg_replace('/[^A-Z0-9 -]/i', '', $regnr));
    $retryAttempt = !empty($data['retry_attempt']);
    $textFitWarning = !empty($data['pdf_text_fit_warning']);
    $textFitWarningText = 'VARNING: Ett eller flera PDF-textfält behövde en teckenstorlek under 5 pt. Granska läsbarheten i den bifogade PDF:en innan den används.';
    $subject = ($retryAttempt ? 'Nytt försök: ' : '') . 'Skadeblankett Bilglas - ' . $safeRegnr;
    if ($retryAttempt) {
        // Retry sends only the already-created PDF and minimal metadata; do not
        // persist or reconstruct customer details for the retry message.
        $bodyText = "Hej,\n\nDetta är ett nytt försök att skicka en tidigare skapad skadeblankett för fordonet " . $safeRegnr . ". Se den bifogade PDF:en.\n\n";
        if ($textFitWarning) {
            $bodyText .= $textFitWarningText . "\n\n";
        }
        $bodyText .= "Med vänlig hälsning\n" . $companyName . "\n";
        $htmlBody = '<!doctype html><html lang="sv"><meta charset="utf-8"><body style="font-family:Arial,sans-serif">';
        $htmlBody .= '<h2>Nytt mejlförsök: skadeblankett</h2><p>Reg.nr: <strong>' . h($safeRegnr) . '</strong></p>';
        $htmlBody .= '<p>Den tidigare skapade skadeblanketten bifogas igen.</p>';
        if ($textFitWarning) {
            $htmlBody .= '<p style="color:#8a3b00"><strong>' . h($textFitWarningText) . '</strong></p>';
        }
        $htmlBody .= '<p>Med vänlig hälsning,<br>' . h($companyName) . '</p></body></html>';
    } else {
        $name = trim((string) ($data['namn_adress'] ?? ''));
        $model = trim((string) ($data['modell'] ?? ''));
        $insurance = trim((string) ($data['forsakringsbolag'] ?? ''));
        $damageDate = trim((string) ($data['skadedatum'] ?? ''));
        $signatureDrawn = !empty($data['signature_drawn']) || !empty($data['signature_png_bytes']);
        $signatureNotice = $signatureDrawn
            ? 'Kundens ritade signatur finns i den bifogade PDF:en.'
            : 'PDF:en saknar ritad signatur. Skriv ut den och låt kunden skriva under för hand innan blanketten används.';

        $bodyText = "Hej,\n\nHär kommer kundunderlaget för fordonet " . $safeRegnr . '. ' . $signatureNotice . " Verkstadsuppgifter och datumfält lämnas tomma för er komplettering.\n\n";
        $bodyText .= 'Kund: ' . str_replace(["\r", "\n"], ', ', $name) . "\n";
        $bodyText .= 'Modell: ' . str_replace(["\r", "\n"], ' ', $model) . "\n";
        $bodyText .= 'Försäkringsbolag: ' . str_replace(["\r", "\n"], ' ', $insurance) . "\n";
        $bodyText .= 'Skadedatum: ' . str_replace(["\r", "\n"], ' ', $damageDate) . "\n";
        $photoNotes = [];
        if (!empty($data['photo_damage'])) {
            $photoNotes[] = 'skada';
        }
        if (!empty($data['photo_front'])) {
            $photoNotes[] = 'front';
        }
        if ($photoNotes !== []) {
            $bodyText .= 'Bilder: ' . implode(', ', $photoNotes) . "\n";
        }
        $bodyText .= "\nSe bifogad PDF.\n";
        if ($textFitWarning) {
            $bodyText .= $textFitWarningText . "\n";
        }
        $bodyText .= "\nMed vänlig hälsning\n" . $companyName . "\n";

        $htmlBody = '<!doctype html><html lang="sv"><meta charset="utf-8"><body style="font-family:Arial,sans-serif">';
        $htmlBody .= '<h2>Skadeblankett Bilglas</h2><p>Reg.nr: <strong>' . h($safeRegnr) . '</strong></p>';
        $htmlBody .= '<p><strong>Kunduppgifter:</strong><br>' . nl2br(h($name), false) . '</p>';
        $htmlBody .= '<p>' . h($signatureNotice) . ' Verkstadsuppgifter och datumfält lämnas tomma för er komplettering.</p>';
        if ($textFitWarning) {
            $htmlBody .= '<p style="color:#8a3b00"><strong>' . h($textFitWarningText) . '</strong></p>';
        }
        $htmlBody .= '<p>Med vänlig hälsning,<br>' . h($companyName) . '</p></body></html>';
    }

    try {
        $boundary = '=_Tadega_' . bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        return false;
    }

    $replyTo = $ownerEmail;
    $headers = "From: Dokumentportalen <" . $ownerEmail . ">\r\n";
    if (is_string($replyTo) && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $headers .= 'Reply-To: ' . $replyTo . "\r\n";
    }
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= 'Content-Type: multipart/mixed; boundary="' . $boundary . '"' . "\r\n";

    $fileContent = @file_get_contents($realPdf);
    if (!is_string($fileContent)) {
        return false;
    }
    // Keep the stored archive filename unique, but give the recipient a clear,
    // stable attachment filename containing only the validated registration.
    $attachmentRegnr = strtoupper((string) preg_replace('/[^A-Z0-9-]/i', '', $regnr));
    if ($attachmentRegnr === '') {
        return false;
    }
    $fileName = 'Skadeblankett ' . $attachmentRegnr . '.pdf';
    $encodedPdf = chunk_split(base64_encode($fileContent));

    $message = '--' . $boundary . "\r\n";
    $message .= "Content-Type: multipart/alternative; boundary=\"" . $boundary . "_alt\"\r\n\r\n";
    $message .= '--' . $boundary . "_alt\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n" . $bodyText . "\r\n";
    $message .= '--' . $boundary . "_alt\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n" . $htmlBody . "\r\n";
    $message .= '--' . $boundary . "_alt--\r\n";
    $message .= '--' . $boundary . "\r\n";
    $message .= 'Content-Type: application/pdf; name="' . $fileName . '"' . "\r\n";
    $message .= "Content-Transfer-Encoding: base64\r\n";
    $message .= 'Content-Disposition: attachment; filename="' . $fileName . '"' . "\r\n\r\n";
    $message .= $encodedPdf . "\r\n--" . $boundary . "--\r\n";

    $delivery = portal_email_transport_send($ownerEmail, $subject, $message, $headers);
    return [
        'accepted' => ($delivery['accepted'] ?? false) === true,
        'transport' => is_string($delivery['transport'] ?? null) ? $delivery['transport'] : 'none',
        'code' => is_string($delivery['code'] ?? null) ? $delivery['code'] : 'unknown',
    ];
}

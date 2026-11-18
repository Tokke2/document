<?php
declare(strict_types=1);

require_once __DIR__ . '/email_transport.php';
require_once __DIR__ . '/protocol_pdf.php';

/**
 * Send the saved protocol PDF to the portal's primary internal recipient only.
 * No customer address, CC or BCC is added here.
 *
 * @return array{accepted:bool,result:string,transport:string,code:string}
 */
function portal_send_protocol_saved_email(array $protocol): array
{
    $settings = function_exists('load_settings') ? load_settings() : [];
    $recipient = is_array($settings) ? ($settings['notification_email'] ?? '') : '';
    if (!is_string($recipient) || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n\x00]/', $recipient)) {
        return ['accepted' => false, 'result' => 'not_configured', 'transport' => 'none', 'code' => 'recipient_not_configured'];
    }

    $pdfBytes = portal_protocol_generate_pdf_bytes($protocol);
    if (!is_string($pdfBytes) || $pdfBytes === '') {
        return ['accepted' => false, 'result' => 'failed', 'transport' => 'none', 'code' => 'pdf_generation_failed'];
    }
    if (strlen($pdfBytes) > 18874368) {
        return ['accepted' => false, 'result' => 'failed', 'transport' => 'none', 'code' => 'attachment_too_large'];
    }

    $singleLine = static function (mixed $value, int $limit = 80): string {
        $text = is_scalar($value) ? (string) $value : '';
        $text = preg_replace('/[\r\n\x00-\x1F\x7F]+/u', ' ', $text) ?? '';
        $text = trim($text);
        return function_exists('mb_substr') ? mb_substr($text, 0, $limit, 'UTF-8') : substr($text, 0, $limit);
    };
    $protocolNumber = $singleLine($protocol['nummer'] ?? '');
    $registration = strtoupper(preg_replace('/[^A-Z0-9-]/i', '', $singleLine($protocol['reg'] ?? '', 20)) ?? '');
    $fileParts = array_values(array_filter([$protocolNumber, $registration], static fn(string $part): bool => $part !== ''));
    $attachmentName = 'Serviceprotokoll' . ($fileParts !== [] ? '-' . implode('-', $fileParts) : '') . '.pdf';
    $attachmentName = preg_replace('/[^A-Za-z0-9._-]/', '-', $attachmentName) ?? 'Serviceprotokoll.pdf';

    $rawSubject = 'Tadega Portal – Serviceprotokoll som fakturaunderlag';
    if ($protocolNumber !== '') {
        $rawSubject .= ' – ' . $protocolNumber;
    }
    $subject = function_exists('mb_encode_mimeheader')
        ? mb_encode_mimeheader($rawSubject, 'UTF-8', 'B', "\r\n")
        : '=?UTF-8?B?' . base64_encode($rawSubject) . '?=';

    $plainText = "Ett serviceprotokoll har sparats i Tadega Portal.\n\n"
        . "PDF-bilagan är avsedd som internt fakturaunderlag. Skapa fakturan manuellt i Fortnox.\n"
        . "Meddelandet skickas endast till portalens primära interna mottagare; ingen kundkopia har skickats.\n";
    if ($protocolNumber !== '') {
        $plainText .= "Protokollnummer: " . $protocolNumber . "\n";
    }
    if ($registration !== '') {
        $plainText .= "Registreringsnummer: " . $registration . "\n";
    }

    try {
        $boundary = '=_TadegaProtocol_' . bin2hex(random_bytes(18));
    } catch (Throwable) {
        return ['accepted' => false, 'result' => 'failed', 'transport' => 'none', 'code' => 'message_build_failed'];
    }
    $headers = "MIME-Version: 1.0\r\n"
        . 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
    $body = '--' . $boundary . "\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
        . quoted_printable_encode($plainText) . "\r\n"
        . '--' . $boundary . "\r\n"
        . 'Content-Type: application/pdf; name="' . $attachmentName . '"' . "\r\n"
        . "Content-Transfer-Encoding: base64\r\n"
        . 'Content-Disposition: attachment; filename="' . $attachmentName . '"' . "\r\n\r\n"
        . chunk_split(base64_encode($pdfBytes), 76, "\r\n")
        . '--' . $boundary . "--\r\n";

    $delivery = portal_email_transport_send($recipient, $subject, $body, $headers);
    $accepted = ($delivery['accepted'] ?? false) === true;
    $transport = is_string($delivery['transport'] ?? null) ? $delivery['transport'] : 'none';
    $code = is_string($delivery['code'] ?? null) ? $delivery['code'] : 'send_failed';
    return [
        'accepted' => $accepted,
        'result' => $accepted ? 'accepted' : 'failed',
        'transport' => $transport,
        'code' => $code,
    ];
}

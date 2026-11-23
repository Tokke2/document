<?php
declare(strict_types=1);

require_once __DIR__ . '/email_transport.php';

/** Aggregate transport-attempt status only; never stores recipients, customers, or document contents. */
function portal_email_status_file(): string
{
    return __DIR__ . '/../storage/email_status.json';
}

function portal_email_status_storage_is_safe(): bool
{
    $storageDir = dirname(portal_email_status_file());
    $guard = $storageDir . '/.htaccess';
    $root = realpath(dirname(__DIR__));
    $realDir = realpath($storageDir);
    if (is_link($storageDir) || is_link($guard) || !is_file($guard) || $root === false || $realDir === false || !is_dir($realDir)) {
        return false;
    }
    return strpos($realDir, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) === 0;
}

function portal_email_status_known_codes(): array
{
    return [
        'accepted', 'php_mail_failed', 'invalid_recipient', 'recipient_not_configured',
        'unsafe_config', 'config_unreadable', 'config_permissions', 'config_invalid_json', 'config_invalid_values',
        'message_invalid', 'connect_failed', 'greeting_failed', 'ehlo_failed', 'starttls_unavailable',
        'tls_failed', 'auth_failed', 'sender_rejected', 'recipient_rejected', 'data_rejected',
        'write_failed', 'message_rejected', 'connection_failed', 'preparation_failed', 'unknown', 'legacy',
    ];
}

function portal_email_status_default(): array
{
    return [
        'invoice' => ['attempts' => 0, 'accepted' => 0, 'failed' => 0, 'not_configured' => 0, 'events' => []],
        'skadeblankett' => ['attempts' => 0, 'accepted' => 0, 'failed' => 0, 'not_configured' => 0, 'events' => []],
    ];
}

function portal_email_status_read(): ?array
{
    $path = portal_email_status_file();
    if (!portal_email_status_storage_is_safe() || is_link($path) || app_safe_data_path($path) === null) {
        return null;
    }
    if (!file_exists($path)) {
        return portal_email_status_default();
    }
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }
    $content = @file_get_contents($path);
    if (!is_string($content) || trim($content) === '') {
        return null;
    }
    try {
        $status = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }
    if (!is_array($status)) {
        return null;
    }
    $default = portal_email_status_default();
    foreach (array_keys($default) as $service) {
        $entry = is_array($status[$service] ?? null) ? $status[$service] : [];
        $events = [];
        foreach (array_slice(is_array($entry['events'] ?? null) ? $entry['events'] : [], -20) as $event) {
            if (!is_array($event)) {
                continue;
            }
            $eventKind = is_string($event['kind'] ?? null) && in_array($event['kind'], ['invoice_saved', 'invoice_retry', 'skade_submission', 'skade_retry', 'test'], true) ? $event['kind'] : 'test';
            $eventResult = is_string($event['result'] ?? null) && in_array($event['result'], ['accepted', 'failed', 'not_configured'], true) ? $event['result'] : 'failed';
            $eventTransport = is_string($event['transport'] ?? null) && in_array($event['transport'], ['smtp', 'php_mail', 'none'], true) ? $event['transport'] : 'php_mail';
            $eventCode = is_string($event['code'] ?? null) && in_array($event['code'], portal_email_status_known_codes(), true) ? $event['code'] : 'legacy';
            $events[] = [
                'at' => is_string($event['at'] ?? null) ? substr($event['at'], 0, 40) : '',
                'kind' => $eventKind,
                'result' => $eventResult,
                'transport' => $eventTransport,
                'code' => $eventCode,
            ];
        }
        $default[$service] = [
            'attempts' => max(0, (int) ($entry['attempts'] ?? 0)),
            'accepted' => max(0, (int) ($entry['accepted'] ?? 0)),
            'failed' => max(0, (int) ($entry['failed'] ?? 0)),
            'not_configured' => max(0, (int) ($entry['not_configured'] ?? 0)),
            'events' => $events,
        ];
    }
    return $default;
}

/** Record one fixed-category event. Events contain only a timestamp, kind, outcome, transport, and allowlisted technical code. */
function portal_email_status_record(string $service, string $result, string $kind, string $transport = 'php_mail', string $code = 'unknown'): bool
{
    if ($result === 'not_configured' && $transport === 'php_mail') {
        $transport = 'none';
    }
    if (!in_array($code, portal_email_status_known_codes(), true)) {
        $code = 'unknown';
    }
    if (!in_array($service, ['invoice', 'skadeblankett'], true) || !in_array($result, ['accepted', 'failed', 'not_configured'], true) || !in_array($kind, ['invoice_saved', 'invoice_retry', 'skade_submission', 'skade_retry', 'test'], true) || !in_array($transport, ['smtp', 'php_mail', 'none'], true)) {
        return false;
    }
    $path = portal_email_status_file();
    $guardPath = $path . '.guard.lock';
    if (!portal_email_status_storage_is_safe() || app_safe_data_path($path) === null || is_link($path) || is_link($path . '.lock') || is_link($guardPath)) {
        return false;
    }
    $oldUmask = umask(0077);
    $lock = @fopen($guardPath, 'c');
    umask($oldUmask);
    if (!is_resource($lock)) {
        return false;
    }
    @chmod($guardPath, 0600);
    if (!@flock($lock, LOCK_EX)) {
        fclose($lock);
        return false;
    }
    $saved = false;
    try {
        $status = portal_email_status_read();
        if (!is_array($status)) {
            return false;
        }
        $entry = $status[$service];
        $entry['attempts']++;
        $entry[$result]++;
        $entry['events'][] = ['at' => date('c'), 'kind' => $kind, 'result' => $result, 'transport' => $transport, 'code' => $code];
        $entry['events'] = array_slice($entry['events'], -20);
        $status[$service] = $entry;
        $saved = save_data($path, $status);
    } finally {
        @flock($lock, LOCK_UN);
        fclose($lock);
    }
    if (class_exists('TadegaPrivacyLogger', false)) {
        $event = $service === 'invoice'
            ? 'invoice.email_' . $result
            : ($result === 'accepted' ? 'skade.email_accepted' : ($result === 'failed' ? 'skade.email_failed' : 'skade.email_not_configured'));
        TadegaPrivacyLogger::event($event);
    }
    return $saved;
}

function portal_email_status_kind_label(string $kind): string
{
    return match ($kind) {
        'invoice_saved' => 'Avisering efter sparat underlag',
        'invoice_retry' => 'Adminens nya försök för underlag',
        'skade_submission' => 'Utskick efter skapad skadeblankett',
        'skade_retry' => 'Manuellt nytt försök',
        'test' => 'Manuellt testutskick',
        default => 'E-posthändelse',
    };
}

function portal_email_status_result_label(string $result): string
{
    return match ($result) {
        'accepted' => 'E-posttransporten accepterade anropet; leverans till inkorgen är inte bekräftad',
        'failed' => 'E-posttransporten kunde inte acceptera utskicket',
        'not_configured' => 'Ingen giltig mottagaradress – inget utskick gjordes',
        default => 'Okänt resultat',
    };
}

function portal_email_status_transport_label(string $transport): string
{
    return match ($transport) {
        'smtp' => 'SMTP',
        'php_mail' => 'PHP mail() fallback',
        'none' => 'Ingen transport',
        default => 'Okänd transport',
    };
}

function portal_email_status_code_label(string $code): string
{
    return match ($code) {
        'accepted' => 'Transportens accepteringssignal mottagen',
        'php_mail_failed' => 'PHP mail() returnerade fel',
        'invalid_recipient' => 'Mottagaradressen är ogiltig',
        'recipient_not_configured' => 'Mottagaradress saknas',
        'unsafe_config' => 'SMTP-lagringen eller dess åtkomstskydd är osäkert',
        'config_unreadable' => 'SMTP-filen kan inte läsas',
        'config_permissions' => 'SMTP-filen måste ha filrättighet 0600',
        'config_invalid_json' => 'SMTP-filen innehåller ogiltig JSON',
        'config_invalid_values' => 'SMTP-värden eller autentisering saknas/är ogiltiga',
        'message_invalid' => 'Meddelandets rubriker eller innehåll kunde inte byggas säkert',
        'connect_failed' => 'Anslutning till SMTP-servern misslyckades',
        'greeting_failed' => 'SMTP-servern svarade inte som väntat vid anslutning',
        'ehlo_failed' => 'SMTP-servern avvisade klientidentifieringen',
        'starttls_unavailable' => 'SMTP-servern erbjuder inte begärd STARTTLS',
        'tls_failed' => 'Krypterad TLS-anslutning kunde inte upprättas/verifieras',
        'auth_failed' => 'SMTP-autentiseringen misslyckades',
        'sender_rejected' => 'SMTP-servern avvisade avsändaradressen',
        'recipient_rejected' => 'SMTP-servern avvisade mottagaradressen',
        'data_rejected' => 'SMTP-servern avvisade överföringen av meddelandet',
        'write_failed' => 'Meddelandet kunde inte överföras till SMTP-servern',
        'message_rejected' => 'SMTP-servern avvisade meddelandet',
        'connection_failed' => 'SMTP-anslutningen avbröts oväntat',
        'preparation_failed' => 'PDF eller meddelande kunde inte förberedas',
        'legacy' => 'Äldre post utan teknisk statuskod',
        default => 'Ingen ytterligare teknisk kod',
    };
}

/** Send a minimal, deliberate test email to the service's fixed internal destination. */
function portal_email_send_test(string $service): array
{
    if (!in_array($service, ['invoice', 'skadeblankett'], true)) {
        return ['result' => 'not_configured', 'recorded' => false];
    }
    if ($service === 'invoice') {
        $settings = load_settings();
        $recipient = $settings['notification_email'] ?? '';
        if (!is_string($recipient) || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return ['result' => 'not_configured', 'transport' => 'none', 'code' => 'recipient_not_configured', 'recorded' => portal_email_status_record('invoice', 'not_configured', 'test', 'none', 'recipient_not_configured')];
        }
        $subject = 'Dokumentportalen – test av e-post för fakturaunderlag';
        $body = "Detta är ett manuellt testutskick från Dokumentportalen för e-postflödet Fakturaunderlag.\n\nE-posttransportens svar visar endast om servern accepterade anropet; det bekräftar inte vidarebefordran eller leverans till inkorgen.\n";
        $kind = 'test';
    } else {
        $settings = load_settings();
        $recipient = $settings['skade_recipient_email'] ?? '';
        if (!is_string($recipient) || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return ['result' => 'not_configured', 'transport' => 'none', 'code' => 'recipient_not_configured', 'recorded' => portal_email_status_record('skadeblankett', 'not_configured', 'test', 'none', 'recipient_not_configured')];
        }
        $subject = 'Dokumentportalen – test av skadeblankettens e-post';
        $body = "Detta är ett manuellt testutskick från Dokumentportalen för e-postflödet Skadeblankett.\n\nInga kunduppgifter eller PDF-bilagor ingår. Mottagaren är vald av admin. E-posttransportens svar bekräftar inte faktisk leverans.\n";
        $kind = 'test';
    }
    $headers = "From: Dokumentportalen <" . $recipient . ">\r\n";
    $headers .= "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    $delivery = portal_email_transport_send($recipient, $subject, $body, $headers);
    $result = ($delivery['accepted'] ?? false) === true ? 'accepted' : 'failed';
    $transport = is_string($delivery['transport'] ?? null) && in_array($delivery['transport'], ['smtp', 'php_mail', 'none', 'unavailable'], true) ? $delivery['transport'] : 'none';
    if ($transport === 'unavailable') {
        $transport = 'none';
    }
    $code = is_string($delivery['code'] ?? null) ? $delivery['code'] : 'unknown';
    $recorded = portal_email_status_record($service, $result, $kind, $transport, $code);
    $testMarked = false;
    if ($service === 'skadeblankett' && $result === 'accepted' && $transport === 'smtp') {
        $testMarked = portal_email_transport_mark_tested(is_string($delivery['revision'] ?? null) ? $delivery['revision'] : '');
    }
    return [
        'result' => $result,
        'transport' => $transport,
        'code' => $code,
        'recorded' => $recorded,
        'test_marked' => $testMarked,
    ];
}

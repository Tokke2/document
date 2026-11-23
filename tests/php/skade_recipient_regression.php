<?php
declare(strict_types=1);
function load_settings(): array { return []; }
require_once __DIR__ . '/../../includes/send_skade_email.php';
$result = send_skadeblankett_email(__DIR__ . '/../../pdf/Bilrutan Skadeblankett.pdf', [], 'ABC123');
if (!is_array($result) || ($result['accepted'] ?? true) !== false || ($result['code'] ?? '') !== 'recipient_not_configured') {
    throw new RuntimeException('Missing recipient must prevent sending.');
}
echo "PASS: unconfigured damage recipient prevents sending.\n";

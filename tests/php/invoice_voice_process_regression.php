<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/invoice_catalog.php';
// Load the actual controller functions without executing its request handler.
$controller = file_get_contents(__DIR__ . '/../../index.php');
$extract = static function (string $from, string $until) use ($controller): void {
    $start = strpos($controller, $from); $end = strpos($controller, $until, $start);
    if ($start === false || $end === false) throw new RuntimeException('Controller functions missing');
    eval(substr($controller, $start, $end - $start));
};
$extract('function portal_post_text(', 'function portal_valid_iso_date(');
$extract('function portal_process_items(', 'function portal_protocol_content_hash(');
$token = str_repeat('a',32) . '.opaque';
$item = ['art'=>'Stenskott', 'qty'=>'2', 'unit'=>'st', 'price'=>'750', 'voice_token'=>$token];
$items = portal_process_items([$item], 'invoice');
if (!is_array($items) || ($items[0]['voice_token'] ?? '') !== $token || $items[0]['qty'] !== 2.0) throw new RuntimeException('Saving must preserve row provenance and normal values');
$item['fixed_lock'] = str_repeat('b',32);
$items = portal_process_items([$item], 'invoice');
if (($items[0]['fixed_lock'] ?? '') !== str_repeat('b',32)) throw new RuntimeException('Saving must preserve historical fixed locks');
$item['fixed_lock'] = 'bad';
if (portal_process_items([$item], 'invoice') !== null) throw new RuntimeException('Malformed fixed locks must be rejected');
unset($item['fixed_lock']);
$item['voice_token'] = '<script>';
if (portal_process_items([$item], 'invoice') !== null) throw new RuntimeException('Invalid provenance must be rejected');
$item['voice_token'] = str_repeat('a', 5000);
if (portal_process_items([$item], 'invoice') !== null) throw new RuntimeException('Oversized provenance must be rejected');
echo "PASS: invoice saved-row provenance validation.\n";

<?php
declare(strict_types=1);
require_once __DIR__ . '/../../functions.php';
require_once __DIR__ . '/../../includes/invoice_catalog.php';
require_once __DIR__ . '/../../includes/invoice_price_history.php';
require_once __DIR__ . '/../../includes/invoice_from_protocol.php';
require_once __DIR__ . '/../../includes/autosaved_drafts.php';
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException($message);
};
$protocol = ['id' => 'abc123', 'nummer' => '123', 'customer' => 'Testkund', 'description' => 'Service', 'signature' => 'secret', 'items' => [
    ['art' => 'Arbete', 'qty' => '2', 'spec' => 'Service'],
    ['art' => 'Material', 'qty' => '3', 'spec' => ''],
]];
$invoice = portal_invoice_from_protocol($protocol, ['0', '0', '../1']);
$assert(count($invoice['items']) === 1 && $invoice['items'][0]['art'] === 'Arbete – Service', 'Only selected rows are copied with specification');
$assert($invoice['items'][0]['price'] === '' && $invoice['items'][0]['qty'] === '2', 'Quantity is copied, price remains unset');
$assert($invoice['source_protocol_id'] === 'abc123' && $invoice['id'] === '', 'Source link retained and no invoice ID reused');
$assert(!isset($invoice['signature']) && count(portal_invoice_from_protocol($protocol, null)['items']) === 0, 'Signatures are not copied; no implicit row selection');
$draft = portal_autosaved_drafts_sanitize_payload('invoice', ['i' => $invoice, 'items' => $invoice['items']]);
$assert(is_array($draft) && $draft['i']['source_protocol_id'] === 'abc123', 'Source link survives autosave');
$voiceToken = str_repeat('a', 32) . '.opaqueToken';
$draftWithVoice = portal_autosaved_drafts_sanitize_payload('invoice', ['i' => ['vat_mode'=>'ex'], 'items' => [['art'=>'Stenskott', 'qty'=>'1', 'unit'=>'st', 'price'=>'100', 'voice_token'=>$voiceToken]]]);
$assert(($draftWithVoice['items'][0]['voice_token'] ?? null) === $voiceToken, 'Opaque row provenance survives autosave');
$lockToken=str_repeat('b',32);
$draftWithLock=portal_autosaved_drafts_sanitize_payload('invoice',['i'=>['vat_mode'=>'ex'],'items'=>[['art'=>'Glasbyte','qty'=>'1','unit'=>'st','price'=>'750','fixed_lock'=>$lockToken]]]);
$assert(($draftWithLock['items'][0]['fixed_lock'] ?? '') === $lockToken, 'Fixed-price lock survives autosave');
$assert(portal_autosaved_drafts_sanitize_payload('invoice', ['i'=>['vat_mode'=>'ex'], 'items'=>[['art'=>'X','qty'=>'1','unit'=>'st','price'=>'1','voice_token'=>'<script>']]]) === null, 'Invalid row provenance is rejected');
$invoice['source_protocol_id'] = '../bad';
$assert(portal_autosaved_drafts_sanitize_payload('invoice', ['i' => $invoice, 'items' => []]) === null, 'Malformed source link rejected');
$history = portal_invoice_price_history([
    ['id' => 'new', 'date' => '2026-01-02', 'vat_mode' => 'ex', 'customer' => 'private', 'items' => [['art' => 'Arbete', 'unit' => 'tim', 'price' => 900]]],
    ['id' => 'old', 'date' => '2025-01-02', 'vat_mode' => 'ex', 'customer' => 'private', 'items' => [['art' => 'arbete', 'unit' => 'tim', 'price' => 850]]],
    ['id' => 'other', 'date' => '2024-01-02', 'vat_mode' => 'inc', 'items' => [['art' => 'arbete', 'unit' => 'tim', 'price' => 1000]]],
], 'new');
$assert(count($history) === 2 && $history[0]['price'] === 850.0, 'Editing excludes current invoice and keeps previous price');
$assert($history[1]['vat'] === 'inc' && !isset($history[0]['customer']), 'VAT modes stay separate and customer data excluded');
echo "PASS: {$checks} invoice assistance checks.\n";

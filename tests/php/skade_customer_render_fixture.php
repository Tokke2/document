<?php
declare(strict_types=1);
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function csrf_field(): string { return '<input type="hidden" name="csrf_token" value="test">'; }
function portal_skade_new_action_token(string $kind, string $id=''): string { return str_repeat('a', 32); }
function render_customer_skade(bool $done = false, bool $internal = false, array $post = [], bool $serverError = false): string {
    $isSkadeCustomerMode = !$internal; $csrfReady = true; $portalLanguage = 'sv';
    $_POST = $post; $_GET = $done ? ['done'=>'1','mail'=>'sent'] : [];
    $portalPostError = $serverError ? 'Kontrollera formuläret och försök igen.' : '';
    ob_start(); include __DIR__.'/../../tabs/skadeblankett.php'; return (string)ob_get_clean();
}

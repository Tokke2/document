<?php
declare(strict_types=1);
/**
 * Compatibility wrapper for the legacy skadeblankett_* logging API.
 * All supplied messages and context are intentionally discarded because they
 * may contain registration numbers, contact details or personal identifiers.
 */
require_once __DIR__ . '/logger.php';

function skade_log(string $message, array $context = [], string $level = 'INFO'): void
{
    $level = strtoupper($level);
    if ($level === 'SUCCESS') {
        TadegaPrivacyLogger::event('skade.pdf_generated');
    } elseif ($level === 'ERROR') {
        TadegaPrivacyLogger::event('skade.pdf_failed');
    } elseif ($level === 'WARN' || $level === 'WARNING') {
        TadegaPrivacyLogger::event('skade.fallback_used');
    } else {
        TadegaPrivacyLogger::event('skade.template_selected');
    }
}

function skade_log_debug(string $message, array $context = []): void
{
    // Debug content is deliberately not persisted.
}

function skade_log_info(string $message, array $context = []): void
{
    TadegaPrivacyLogger::event('skade.template_selected');
}

function skade_log_warn(string $message, array $context = []): void
{
    TadegaPrivacyLogger::event('skade.fallback_used');
}

function skade_log_error(string $message, array $context = []): void
{
    TadegaPrivacyLogger::event('skade.pdf_failed');
}

function skade_log_success(string $message, array $context = []): void
{
    TadegaPrivacyLogger::event('skade.pdf_generated');
}

function skade_timer_start(string $name = 'default'): void
{
    $GLOBALS['_tadega_safe_timer'] = microtime(true);
}

function skade_timer_end(string $name = 'default', string $label = ''): float
{
    if (!isset($GLOBALS['_tadega_safe_timer'])) {
        return 0.0;
    }
    $elapsed = microtime(true) - (float) $GLOBALS['_tadega_safe_timer'];
    unset($GLOBALS['_tadega_safe_timer']);
    TadegaPrivacyLogger::event('system.timer_completed');
    return $elapsed;
}

function skade_log_step(string $step, array $data = []): void
{
    TadegaPrivacyLogger::event('skade.form_received');
}

function skade_log_template(string $forcedPath, ?string $resolvedPath, bool $success): void
{
    TadegaPrivacyLogger::event($success ? 'skade.template_selected' : 'skade.fallback_used');
}

function skade_log_input(array $data): void
{
    // Never log submitted customer/vehicle/insurance data.
    TadegaPrivacyLogger::event('skade.form_received');
}

function skade_log_result(bool $ok, string $pdfPath = '', string $error = ''): void
{
    TadegaPrivacyLogger::event($ok ? 'skade.pdf_generated' : 'skade.pdf_failed');
}

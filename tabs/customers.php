<?php
declare(strict_types=1);
/**
 * tabs/customers.php — refreshed customer register for the Tadega app.
 *
 * Drop-in assumptions inherited from the original file:
 *   - The app defines CUSTOMERS_FILE.
 *   - load_data(CUSTOMERS_FILE) returns an array of customers.
 *   - save_data(CUSTOMERS_FILE, $customers) persists that array.
 *   - The parent app authenticates users before including this tab.
 *
 * PHP 8.5+. Strict typing is enabled.
 */

// Start the session before output so forms can use a per-session CSRF token.
$tc_session_ready = session_status() === PHP_SESSION_ACTIVE;
if (!$tc_session_ready && !headers_sent()) {
    $tc_session_ready = @session_start();
    $tc_session_ready = $tc_session_ready || session_status() === PHP_SESSION_ACTIVE;
}

// Customer pages should not be cached by shared/browser caches.
if (!headers_sent()) {
    header('Cache-Control: no-store, private, max-age=0, must-revalidate');
    header('Pragma: no-cache');
}

$tc_csrf_token = '';
if ($tc_session_ready) {
    try {
        if (
            !isset($_SESSION['tadega_customers_csrf_v1']) ||
            !is_string($_SESSION['tadega_customers_csrf_v1']) ||
            !preg_match('/\A[a-f0-9]{64}\z/', $_SESSION['tadega_customers_csrf_v1'])
        ) {
            $_SESSION['tadega_customers_csrf_v1'] = bin2hex(random_bytes(32));
        }
        $tc_csrf_token = $_SESSION['tadega_customers_csrf_v1'];
    } catch (Throwable $e) {
        $tc_csrf_token = '';
    }
}
$tc_csrf_ready = $tc_session_ready && $tc_csrf_token !== '';

$tc_escape = static function (mixed $value): string {
    if (!is_scalar($value)) {
        $value = '';
    }
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

$tc_scalar_string = static function (mixed $value): string {
    return is_scalar($value) ? (string) $value : '';
};

$tc_text_length = static function (string $value): int {
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }
    $matched = preg_match_all('/./us', $value, $unused);
    return $matched === false ? strlen($value) : $matched;
};

// The previous version stored htmlspecialchars()-escaped values. Decode once on
// read so existing records remain readable; all output is escaped on render.
$tc_legacy_text = static function (mixed $value) use ($tc_scalar_string): string {
    $text = $tc_scalar_string($value);
    return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
};

// Route customer audit events through the shared privacy-first logger.
$tc_audit = static function (string $event): void {
    if (class_exists('TadegaPrivacyLogger')) {
        TadegaPrivacyLogger::event($event);
    }
};

$tc_data_ready = defined('CUSTOMERS_FILE') && function_exists('load_data') && function_exists('save_data');
$tc_data_error = '';
$tc_customers = [];

if ($tc_data_ready) {
    try {
        $tc_raw_customers = load_data(CUSTOMERS_FILE);
        if (!is_array($tc_raw_customers)) {
            $tc_data_ready = false;
            $tc_data_error = 'Kundregistret kunde inte läsas. Inga ändringar har gjorts.';
        } else {
            foreach ($tc_raw_customers as $tc_raw_customer) {
                if (!is_array($tc_raw_customer)) {
                    continue;
                }
                $tc_id = $tc_scalar_string($tc_raw_customer['id'] ?? '');
                if ($tc_id === '') {
                    continue;
                }
                $tc_legacy_reference = $tc_legacy_text($tc_raw_customer['reference'] ?? '');
                $tc_references = [];
                $tc_raw_references = $tc_raw_customer['references'] ?? [];
                if (is_array($tc_raw_references)) {
                    foreach ($tc_raw_references as $tc_raw_reference) {
                        $tc_reference = trim($tc_legacy_text($tc_raw_reference));
                        if ($tc_reference !== '' && !in_array($tc_reference, $tc_references, true)) {
                            $tc_references[] = $tc_reference;
                        }
                    }
                }
                if ($tc_legacy_reference !== '' && !in_array($tc_legacy_reference, $tc_references, true)) {
                    array_unshift($tc_references, $tc_legacy_reference);
                }
                $tc_references = array_values($tc_references);
                $tc_customers[] = [
                    'id' => $tc_id,
                    'name' => $tc_legacy_text($tc_raw_customer['name'] ?? ''),
                    'phone' => $tc_legacy_text($tc_raw_customer['phone'] ?? ''),
                    'email' => $tc_legacy_text($tc_raw_customer['email'] ?? ''),
                    'reference' => $tc_references[0] ?? '', // Retain compatibility with the legacy single-reference field.
                    'references' => $tc_references,
                    'personal_id' => $tc_legacy_text($tc_raw_customer['personal_id'] ?? ''),
                    'property_id' => $tc_legacy_text($tc_raw_customer['property_id'] ?? ''),
                ];
            }
        }
    } catch (Throwable $e) {
        $tc_data_ready = false;
        $tc_data_error = 'Kundregistret kunde inte läsas. Inga ändringar har gjorts.';
        error_log('Customer registry read failed.');
    }
} else {
    $tc_data_error = 'Appens kundregister saknar nödvändig konfiguration.';
}

$tc_original_customers = $tc_customers;
$tc_duplicate_field_labels = [
    'name' => 'namn',
    'phone' => 'telefonnummer',
    'email' => 'e-postadress',
    'personal_id' => 'person-/organisationsnummer',
    'property_id' => 'fastighetsbeteckning',
];
$tc_normalize_duplicate_value = static function (mixed $value, string $kind) use ($tc_scalar_string): string {
    $text = trim($tc_scalar_string($value));
    if ($text === '') {
        return '';
    }
    if ($kind === 'phone') {
        $digits = preg_replace('/\\D+/', '', $text);
        if (!is_string($digits)) {
            return '';
        }
        if (str_starts_with($digits, '0046')) {
            $digits = '0' . substr($digits, 4);
        } elseif (str_starts_with($digits, '46') && strlen($digits) >= 10 && strlen($digits) <= 12) {
            $digits = '0' . substr($digits, 2);
        }
        return strlen($digits) >= 7 ? $digits : '';
    }
    if (class_exists('Normalizer')) {
        try {
            $unicodeNormalized = Normalizer::normalize($text, Normalizer::FORM_KC);
            if (is_string($unicodeNormalized)) {
                $text = $unicodeNormalized;
            }
        } catch (Throwable $e) {
            // Continue with conservative case folding if intl is unavailable or rejects legacy text.
        }
    }
    if (function_exists('mb_strtolower')) {
        try {
            $text = mb_strtolower($text, 'UTF-8');
        } catch (Throwable $e) {
            $text = strtolower(strtr($text, ['Å' => 'å', 'Ä' => 'ä', 'Ö' => 'ö']));
        }
    } else {
        $text = strtolower(strtr($text, ['Å' => 'å', 'Ä' => 'ä', 'Ö' => 'ö']));
    }
    if ($kind === 'email') {
        return str_contains($text, '@') ? $text : '';
    }
    if ($kind === 'personal_id') {
        $normalized = preg_replace('/[^\\p{L}\\p{N}]+/u', '', $text);
        return is_string($normalized) && strlen($normalized) >= 6 ? $normalized : '';
    }
    if ($kind === 'property_id') {
        $normalized = preg_replace('/[\\p{P}\\p{S}\\s]+/u', '', $text);
        return is_string($normalized) && strlen($normalized) >= 4 ? $normalized : '';
    }
    if ($kind === 'name') {
        $normalized = preg_replace('/[\\p{P}\\p{S}]+/u', ' ', $text);
        $normalized = is_string($normalized) ? preg_replace('/[\\s\\p{Z}]+/u', ' ', $normalized) : null;
        return is_string($normalized) && strlen(trim($normalized)) >= 3 ? trim($normalized) : '';
    }
    return '';
};
$tc_find_possible_duplicates = static function (array $candidate, array $records, string $excludeId = '') use ($tc_duplicate_field_labels, $tc_normalize_duplicate_value, $tc_scalar_string): array {
    $candidateValues = [];
    foreach ($tc_duplicate_field_labels as $field => $_label) {
        $candidateValues[$field] = $tc_normalize_duplicate_value($candidate[$field] ?? '', $field);
    }
    $matches = [];
    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }
        $recordId = $tc_scalar_string($record['id'] ?? '');
        if ($recordId === '' || ($excludeId !== '' && $recordId === $excludeId)) {
            continue;
        }
        $matchedFields = [];
        foreach ($tc_duplicate_field_labels as $field => $label) {
            $candidateValue = $candidateValues[$field] ?? '';
            if ($candidateValue === '') {
                continue;
            }
            $recordValue = $tc_normalize_duplicate_value($record[$field] ?? '', $field);
            if ($recordValue !== '' && $candidateValue === $recordValue) {
                $matchedFields[] = $label;
            }
        }
        if ($matchedFields !== []) {
            $matches[] = [
                'id' => $recordId,
                'name' => $tc_scalar_string($record['name'] ?? ''),
                'fields' => $matchedFields,
            ];
        }
    }
    return $matches;
};
$tc_post_error = '';
$tc_form_errors = [];
$tc_duplicate_matches = [];
$tc_duplicate_warning = false;
$tc_old_input = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $tc_post_token = $_POST['csrf_token'] ?? '';
    if (!$tc_csrf_ready || !is_string($tc_post_token) || !hash_equals($tc_csrf_token, $tc_post_token)) {
        http_response_code(403);
        $tc_post_error = 'Säkerhetskontrollen misslyckades. Ladda om sidan och försök igen.';
    } elseif (!$tc_data_ready) {
        http_response_code(503);
        $tc_post_error = $tc_data_error !== '' ? $tc_data_error : 'Kundregistret är tillfälligt otillgängligt.';
    } else {
        $tc_action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';

        if ($tc_action === 'save_customer') {
            $tc_old_input = [
                'id' => '',
                'name' => '',
                'phone' => '',
                'email' => '',
                'references' => [],
                'personal_id' => '',
                'property_id' => '',
            ];
            $tc_input_types_ok = true;
            $tc_fields = ['name', 'phone', 'email', 'personal_id', 'property_id'];

            foreach ($tc_fields as $tc_field) {
                $tc_value = $_POST[$tc_field] ?? '';
                if (!is_string($tc_value)) {
                    $tc_input_types_ok = false;
                    $tc_value = '';
                }
                $tc_value = trim($tc_value);
                $tc_old_input[$tc_field] = $tc_value;

                if (preg_match('//u', $tc_value) !== 1) {
                    $tc_form_errors[$tc_field] = 'Använd giltig text.';
                } elseif (preg_match('/[\x00-\x1F\x7F]/u', $tc_value)) {
                    $tc_form_errors[$tc_field] = 'Ta bort radbrytningar och kontrolltecken.';
                }
            }

            $tc_reference_inputs = $_POST['references'] ?? [];
            if (!is_array($tc_reference_inputs)) {
                $tc_input_types_ok = false;
                $tc_form_errors['references'] = 'Referenserna måste skickas som en lista.';
                $tc_reference_inputs = [];
            } elseif (count($tc_reference_inputs) > 20) {
                $tc_form_errors['references'] = 'Lägg in högst 20 referenser per kund.';
            }
            $tc_seen_references = [];
            foreach (array_slice($tc_reference_inputs, 0, 20) as $tc_reference_index => $tc_reference_value) {
                if (!is_string($tc_reference_value)) {
                    $tc_input_types_ok = false;
                    $tc_form_errors['references'] = 'En referens innehöll ett ogiltigt värde.';
                    continue;
                }
                $tc_reference_value = trim($tc_reference_value);
                $tc_old_input['references'][] = $tc_reference_value;
                if (preg_match('//u', $tc_reference_value) !== 1) {
                    $tc_form_errors['references'] = 'Använd giltig text för varje referens.';
                } elseif (preg_match('/[\x00-\x1F\x7F]/u', $tc_reference_value)) {
                    $tc_form_errors['references'] = 'Ta bort radbrytningar och kontrolltecken från referenserna.';
                } elseif ($tc_text_length($tc_reference_value) > 120) {
                    $tc_form_errors['references'] = 'Varje referens får innehålla högst 120 tecken.';
                }
                if ($tc_reference_value !== '') {
                    $tc_reference_key = function_exists('mb_strtolower')
                        ? mb_strtolower($tc_reference_value, 'UTF-8')
                        : strtolower($tc_reference_value);
                    if (isset($tc_seen_references[$tc_reference_key])) {
                        $tc_form_errors['references'] = 'Samma referens finns redan under kunden.';
                    }
                    $tc_seen_references[$tc_reference_key] = true;
                }
            }

            $tc_post_id = $_POST['id'] ?? '';
            if (!is_string($tc_post_id)) {
                $tc_input_types_ok = false;
                $tc_post_id = '';
            }
            $tc_post_id = trim($tc_post_id);
            $tc_old_input['id'] = $tc_post_id;

            if (!$tc_input_types_ok) {
                $tc_form_errors['form'] = 'Formuläret innehöll ett ogiltigt fält. Kontrollera uppgifterna och försök igen.';
            }
            if ($tc_post_id !== '' && (strlen($tc_post_id) > 128 || preg_match('/[\x00-\x1F\x7F]/', $tc_post_id))) {
                $tc_form_errors['form'] = 'Kundens ID är ogiltigt. Ladda om sidan och försök igen.';
            }

            $tc_limits = [
                'name' => 160,
                'phone' => 32,
                'email' => 254,
                'personal_id' => 40,
                'property_id' => 120,
            ];
            foreach ($tc_limits as $tc_field => $tc_max) {
                if (!isset($tc_form_errors[$tc_field]) && $tc_text_length($tc_old_input[$tc_field]) > $tc_max) {
                    $tc_form_errors[$tc_field] = 'Max ' . $tc_max . ' tecken.';
                }
            }

            if ($tc_old_input['name'] === '') {
                $tc_form_errors['name'] = 'Fyll i kundens namn.';
            }
            if ($tc_old_input['phone'] === '') {
                $tc_form_errors['phone'] = 'Fyll i ett telefonnummer.';
            } elseif (strlen((string) preg_replace('/[^0-9]/', '', $tc_old_input['phone'])) < 5) {
                // Keep the phone field format-friendly (spaces, extensions and
                // international prefixes are common); require enough digits.
                $tc_form_errors['phone'] = 'Ange minst fem siffror, till exempel +46 70 123 45 67.';
            }
            if ($tc_old_input['email'] !== '' && filter_var($tc_old_input['email'], FILTER_VALIDATE_EMAIL) === false) {
                $tc_form_errors['email'] = 'Ange en giltig e-postadress.';
            }

            $tc_edit_index = null;
            if ($tc_post_id !== '') {
                foreach ($tc_customers as $tc_index => $tc_customer) {
                    if ($tc_customer['id'] === $tc_post_id) {
                        $tc_edit_index = $tc_index;
                        break;
                    }
                }
                if ($tc_edit_index === null) {
                    $tc_form_errors['form'] = 'Kunden kunde inte hittas. Ladda om sidan och försök igen.';
                }
            }

            if (empty($tc_form_errors)) {
                try {
                    $tc_new_id = $tc_post_id !== '' ? $tc_post_id : bin2hex(random_bytes(16));
                    $tc_clean_references = array_values(array_filter(
                        $tc_old_input['references'],
                        static fn (string $tc_reference): bool => $tc_reference !== ''
                    ));
                    $tc_new_customer = [
                        'id' => $tc_new_id,
                        'name' => $tc_old_input['name'],
                        'phone' => $tc_old_input['phone'],
                        'email' => $tc_old_input['email'],
                        'reference' => $tc_clean_references[0] ?? '', // Keep the legacy field populated with the first reference.
                        'references' => $tc_clean_references,
                        'personal_id' => $tc_old_input['personal_id'],
                        'property_id' => $tc_old_input['property_id'],
                    ];

                    $tc_duplicate_matches = $tc_find_possible_duplicates($tc_new_customer, $tc_customers, $tc_new_id);
                    $tc_confirm_duplicates = isset($_POST['confirm_possible_duplicates'])
                        && is_string($_POST['confirm_possible_duplicates'])
                        && $_POST['confirm_possible_duplicates'] === '1';
                    if ($tc_duplicate_matches !== [] && !$tc_confirm_duplicates) {
                        $tc_duplicate_warning = true;
                    } else {
                        if ($tc_edit_index === null) {
                            $tc_customers[] = $tc_new_customer;
                            $tc_event = 'customer.created';
                            $tc_message = 'created';
                        } else {
                            $tc_customers[$tc_edit_index] = $tc_new_customer;
                            $tc_event = 'customer.updated';
                            $tc_message = 'updated';
                        }

                        $tc_save_result = save_data(CUSTOMERS_FILE, array_values($tc_customers));
                        if ($tc_save_result === false) {
                            throw new RuntimeException('Customer registry save failed.');
                        }
                        $tc_audit($tc_event);
                        portal_redirect('customers', $tc_message);
                    }
                } catch (Throwable $e) {
                    $tc_customers = $tc_original_customers;
                    $tc_post_error = 'Kunden kunde inte sparas just nu. Försök igen.';
                    error_log('Customer registry save failed.');
                }
            }
        } elseif ($tc_action === 'delete_customer') {
            $tc_delete_id = $_POST['id'] ?? '';
            if (!is_string($tc_delete_id) || $tc_delete_id === '' || strlen($tc_delete_id) > 128) {
                $tc_post_error = 'Kunden kunde inte raderas. Ladda om sidan och försök igen.';
            } else {
                $tc_found = false;
                foreach ($tc_customers as $tc_index => $tc_customer) {
                    if ($tc_customer['id'] === $tc_delete_id) {
                        unset($tc_customers[$tc_index]);
                        $tc_found = true;
                        break;
                    }
                }

                if (!$tc_found) {
                    $tc_post_error = 'Kunden hittades inte. Listan kan ha uppdaterats.';
                } else {
                    try {
                        $tc_save_result = save_data(CUSTOMERS_FILE, array_values($tc_customers));
                        if ($tc_save_result === false) {
                            throw new RuntimeException('Customer registry delete failed.');
                        }
                        $tc_audit('customer.deleted');
                        portal_redirect('customers', 'deleted');
                    } catch (Throwable $e) {
                        $tc_customers = $tc_original_customers;
                        $tc_post_error = 'Kunden kunde inte raderas just nu. Försök igen.';
                        error_log('Customer registry delete failed.');
                    }
                }
            }
        } else {
            http_response_code(400);
            $tc_post_error = 'Åtgärden kunde inte genomföras.';
        }
    }
}

$tc_messages = [
    'created' => ['success', 'Kunden har lagts till.'],
    'updated' => ['success', 'Kunduppgifterna har sparats.'],
    'saved' => ['success', 'Kunduppgifterna har sparats.'], // Backward compatibility with the old tab.
    'deleted' => ['success', 'Kunden har raderats.'],
];
$tc_message_key = isset($_GET['msg']) && is_string($_GET['msg']) ? $_GET['msg'] : '';
$tc_notice = $tc_messages[$tc_message_key] ?? null;

$tc_requested_edit_id = isset($_GET['edit_id']) && is_string($_GET['edit_id']) ? $_GET['edit_id'] : '';
if ($tc_old_input !== null && isset($tc_old_input['id']) && $tc_old_input['id'] !== '') {
    $tc_requested_edit_id = $tc_old_input['id'];
}
$tc_edit_customer = null;
foreach ($tc_customers as $tc_customer) {
    if ($tc_requested_edit_id !== '' && $tc_customer['id'] === $tc_requested_edit_id) {
        $tc_edit_customer = $tc_customer;
        break;
    }
}
$tc_edit_not_found = $tc_requested_edit_id !== '' && $tc_edit_customer === null;

$tc_empty_customer = [
    'id' => '',
    'name' => '',
    'phone' => '',
    'email' => '',
    'reference' => '',
    'references' => [],
    'personal_id' => '',
    'property_id' => '',
];
$tc_form_values = $tc_edit_customer !== null ? $tc_edit_customer : $tc_empty_customer;
if ($tc_old_input !== null) {
    $tc_form_values = array_merge($tc_form_values, $tc_old_input);
}
$tc_form_is_edit = $tc_edit_customer !== null || ($tc_old_input !== null && $tc_old_input['id'] !== '');
$tc_form_id = $tc_form_is_edit ? ($tc_form_values['id'] ?? '') : '';
$tc_extra_open =
    !empty($tc_form_values['references']) ||
    !empty($tc_form_values['personal_id']) ||
    !empty($tc_form_values['property_id']) ||
    isset($tc_form_errors['references']) ||
    isset($tc_form_errors['personal_id']) ||
    isset($tc_form_errors['property_id']);
$tc_actions_ready = $tc_data_ready && $tc_csrf_ready;
?>

<style>
    .tc-wrap {
        --tc-ink: #142d36;
        --tc-muted: #61757b;
        --tc-border: #d9e5e3;
        --tc-soft: #f0f6f3;
        --tc-primary: #0b7a72;
        --tc-primary-dark: #075c59;
        --tc-danger: #b42318;
        --tc-success: #067647;
        max-width: 1180px;
        margin: 0 auto;
        padding: clamp(16px, 3vw, 34px);
        color: var(--tc-ink);
        font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    }
    .tc-wrap *, .tc-wrap *::before, .tc-wrap *::after { box-sizing: border-box; }
    .tc-count-badge { display:inline-flex; align-items:center; gap:8px; margin-top:10px; padding:8px 12px; border:1px solid var(--tc-border); border-radius:999px; background:#fff; color:var(--tc-muted); font-size:.8rem; white-space:nowrap; }
    .tc-count-badge strong { color:var(--tc-ink); font-size:1rem; }
    .tc-panel { overflow:hidden; border:1px solid var(--tc-border); border-radius:18px; background:#fff; box-shadow:0 5px 20px rgba(16,24,40,.035); }
    .tc-form-panel { margin-bottom:26px; }
    .tc-panel-head { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; padding:22px 24px 0; }
    .tc-panel-title { margin:0; font-size:1.08rem; letter-spacing:-.02em; }
    .tc-panel-help { margin:5px 0 0; color:var(--tc-muted); font-size:.88rem; line-height:1.45; }
    .tc-form { padding:20px 24px 24px; }
    .tc-fields { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:16px; }
    .tc-field { min-width:0; }
    .tc-field-wide { grid-column:1 / -1; }
    .tc-reference-list { display:grid; gap:8px; }
    .tc-reference-row { display:grid; grid-template-columns:minmax(0, 1fr) auto; align-items:center; gap:8px; }
    .tc-reference-row .tc-input { min-width:0; }
    .tc-reference-remove { min-height:42px; }
    .tc-add-reference { min-height:38px; margin-top:8px; padding:8px 11px; font-size:.79rem; }
    .tc-label { display:flex; align-items:center; gap:6px; margin:0 0 7px; color:#344054; font-size:.81rem; font-weight:700; }
    .tc-required { color:var(--tc-danger); }
    .tc-optional { color:#98a2b3; font-size:.73rem; font-weight:500; }
    .tc-input { display:block; width:100%; min-height:46px; padding:11px 13px; border:1px solid #d0d5dd; border-radius:10px; outline:0; background:#fff; color:var(--tc-ink); font:inherit; font-size:.94rem; transition:border-color .16s, box-shadow .16s, background .16s; }
    .tc-input::placeholder { color:#98a2b3; }
    .tc-input:hover { border-color:#aeb8c5; }
    .tc-input:focus { border-color:var(--tc-primary); box-shadow:0 0 0 3px rgba(11,122,114,.13); }
    .tc-input[aria-invalid="true"] { border-color:var(--tc-danger); }
    .tc-help { margin:6px 0 0; color:var(--tc-muted); font-size:.76rem; line-height:1.45; }
    .tc-field-error { margin:6px 0 0; color:var(--tc-danger); font-size:.79rem; font-weight:600; }
    .tc-extra { margin-top:17px; border-top:1px solid var(--tc-border); }
    .tc-extra summary { width:fit-content; padding:15px 0 1px; color:var(--tc-primary); cursor:pointer; font-size:.87rem; font-weight:750; list-style:none; }
    .tc-extra summary::-webkit-details-marker { display:none; }
    .tc-extra summary::before { content:"＋"; display:inline-block; margin-right:8px; transition:transform .15s; }
    .tc-extra[open] summary::before { transform:rotate(45deg); }
    .tc-extra-grid { padding-top:16px; }
    .tc-privacy-note { display:flex; gap:9px; align-items:flex-start; margin:14px 0 0; padding:11px 13px; border:1px solid #e9eef4; border-radius:10px; background:#f8fafc; color:#586577; font-size:.78rem; line-height:1.5; }
    .tc-form-actions { display:flex; align-items:center; gap:10px; margin-top:20px; }
    .tc-btn { display:inline-flex; justify-content:center; align-items:center; gap:8px; min-height:43px; padding:10px 15px; border:1px solid transparent; border-radius:10px; cursor:pointer; font:inherit; font-size:.86rem; font-weight:750; line-height:1.2; text-decoration:none; transition:background .15s, border-color .15s, transform .15s; }
    .tc-btn:focus-visible, .tc-link-btn:focus-visible, .tc-search:focus-visible, .tc-extra summary:focus-visible { outline:3px solid rgba(11,122,114,.3); outline-offset:2px; }
    .tc-btn-primary { background:var(--tc-primary); color:#fff; }
    .tc-btn-primary:hover { background:var(--tc-primary-dark); }
    .tc-btn-secondary { border-color:var(--tc-border); background:#fff; color:#344054; }
    .tc-btn-secondary:hover { background:var(--tc-soft); }
    .tc-btn-danger { border-color:#fecdca; background:#fff; color:var(--tc-danger); }
    .tc-btn-danger:hover { background:#fff5f4; }
    .tc-btn:disabled { cursor:not-allowed; opacity:.55; }
    .tc-alert { display:flex; align-items:flex-start; gap:10px; margin:0 0 18px; padding:13px 15px; border:1px solid; border-radius:12px; font-size:.88rem; line-height:1.5; }
    .tc-alert-success { border-color:#abefc6; background:#ecfdf3; color:#05603a; }
    .tc-alert-error { border-color:#fecdca; background:#fff5f4; color:#912018; }
    .tc-alert-info { border-color:#b2ddff; background:#eff8ff; color:#175cd3; }
    .tc-alert-warning { border-color:#fedf89; background:#fffaeb; color:#7a2e0e; }
    .tc-alert strong { display:block; margin-bottom:2px; }
    .tc-duplicate-matches { display:grid; gap:6px; margin:8px 0 0; padding-left:20px; }
    .tc-duplicate-matches a { color:inherit; font-weight:750; text-decoration:underline; text-underline-offset:2px; }
    .tc-duplicate-matches span { color:#7a2e0e; }
    .tc-list-head { display:flex; justify-content:space-between; align-items:center; gap:18px; margin-bottom:13px; }
    .tc-list-title { margin:0; font-size:1.13rem; letter-spacing:-.02em; }
    .tc-list-subtitle { margin:5px 0 0; color:var(--tc-muted); font-size:.84rem; }
    .tc-search-wrap { position:relative; width:min(100%, 350px); }
    .tc-search-icon { position:absolute; left:13px; top:50%; width:17px; height:17px; color:#98a2b3; transform:translateY(-50%); pointer-events:none; }
    .tc-search { display:block; width:100%; min-height:43px; padding:10px 13px 10px 39px; border:1px solid var(--tc-border); border-radius:10px; outline:0; background:#fff; color:var(--tc-ink); font:inherit; font-size:.88rem; }
    .tc-search:focus { border-color:var(--tc-primary); box-shadow:0 0 0 3px rgba(11,122,114,.12); }
    .tc-results-meta { margin:0 0 12px; color:var(--tc-muted); font-size:.78rem; }
    .tc-customer-list { display:grid; gap:11px; }
    .tc-customer-card { display:flex; justify-content:space-between; align-items:center; gap:20px; padding:17px 19px; border:1px solid var(--tc-border); border-radius:14px; background:#fff; transition:border-color .16s, box-shadow .16s, transform .16s; }
    .tc-customer-card:hover { border-color:#c7d3df; box-shadow:0 5px 16px rgba(16,24,40,.045); transform:translateY(-1px); }
    .tc-customer-main { min-width:0; }
    .tc-customer-name { margin:0; color:var(--tc-ink); font-size:1rem; font-weight:750; overflow-wrap:anywhere; }
    .tc-customer-contact { display:flex; flex-wrap:wrap; gap:7px 15px; margin-top:7px; color:var(--tc-muted); font-size:.83rem; }
    .tc-contact-item { display:inline-flex; align-items:center; gap:6px; overflow-wrap:anywhere; }
    .tc-contact-item svg { width:14px; height:14px; flex:0 0 auto; color:#8492a6; }
    .tc-tag-row { display:flex; flex-wrap:wrap; gap:6px; margin-top:10px; }
    .tc-tag { display:inline-flex; align-items:center; max-width:100%; padding:4px 8px; border:1px solid #e7edf3; border-radius:999px; background:#f8fafc; color:#586577; font-size:.72rem; overflow-wrap:anywhere; }
    .tc-card-actions { display:flex; align-items:center; gap:8px; flex:0 0 auto; }
    .tc-link-btn { display:inline-flex; justify-content:center; align-items:center; min-height:36px; padding:8px 10px; border:1px solid var(--tc-border); border-radius:9px; background:#fff; color:#344054; cursor:pointer; font:inherit; font-size:.76rem; font-weight:750; text-decoration:none; }
    .tc-link-btn:hover { background:var(--tc-soft); }
    .tc-delete-form { margin:0; }
    .tc-link-danger { color:var(--tc-danger); border-color:#fecdca; }
    .tc-link-danger:hover { background:#fff5f4; }
    .tc-empty { padding:36px 20px; border:1px dashed #d0d5dd; border-radius:14px; background:#fbfcfe; text-align:center; }
    .tc-empty-icon { display:grid; place-items:center; width:44px; height:44px; margin:0 auto 13px; border-radius:14px; background:#eaf3f6; color:var(--tc-primary); font-size:1.2rem; }
    .tc-empty h3 { margin:0; font-size:1rem; }
    .tc-empty p { margin:7px auto 0; max-width:420px; color:var(--tc-muted); font-size:.86rem; line-height:1.55; }
    .tc-empty .tc-btn { margin-top:16px; }
    .tc-visually-hidden { position:absolute!important; width:1px!important; height:1px!important; padding:0!important; margin:-1px!important; overflow:hidden!important; clip:rect(0,0,0,0)!important; white-space:nowrap!important; border:0!important; }
    [hidden] { display:none!important; }
    @media (max-width:760px) {
        .tc-panel-head, .tc-form { padding-left:18px; padding-right:18px; }
        .tc-list-head { align-items:stretch; flex-direction:column; }
        .tc-search-wrap { width:100%; }
        .tc-customer-card { align-items:flex-start; flex-direction:column; }
        .tc-card-actions { width:100%; justify-content:flex-end; }
    }
    @media (max-width:560px) {
        .tc-wrap { padding:0 0 24px; }
        .tc-fields { grid-template-columns:1fr; gap:13px; }
        .tc-form { padding-bottom:calc(88px + env(safe-area-inset-bottom)); }
        .tc-form-actions { align-items:stretch; flex-direction:column; }
        .tc-form-actions .tc-btn { width:100%; }
        .tc-form-actions .tc-btn-primary.tc-mobile-fixed {
            position:fixed!important;
            left:max(14px,env(safe-area-inset-left));
            right:max(14px,env(safe-area-inset-right));
            bottom:calc(6px + env(safe-area-inset-bottom));
            z-index:35;
            width:auto!important;
            min-height:48px!important;
            padding:8px 10px;
            font-size:.84rem;
            box-shadow:0 8px 24px rgba(11,122,114,.24);
        }
        .tc-count-badge { align-self:flex-start; }
        .tc-card-actions { justify-content:stretch; }
        .tc-card-actions > *, .tc-card-actions form, .tc-card-actions form button { flex:1; }
        .tc-card-actions form button { width:100%; }
    }
    @media (max-width:900px) and (max-height:560px) and (orientation:landscape) {
        .tc-form { padding-bottom:calc(88px + env(safe-area-inset-bottom)); }
        .tc-form-actions .tc-btn-primary.tc-mobile-fixed {
            position:fixed!important;
            left:max(14px,env(safe-area-inset-left));
            right:max(14px,env(safe-area-inset-right));
            bottom:calc(6px + env(safe-area-inset-bottom));
            z-index:35;
            width:auto!important;
            min-height:48px!important;
            padding:7px 10px;
            font-size:.8rem;
            box-shadow:0 8px 24px rgba(11,122,114,.24);
        }
    }
    @media (prefers-reduced-motion: reduce) {
        .tc-wrap *, .tc-wrap *::before, .tc-wrap *::after { scroll-behavior:auto!important; transition:none!important; }
    }
</style>

<section class="tc-wrap" aria-label="Kundregister">

    <?php if ($tc_notice !== null): ?>
        <div class="tc-alert tc-alert-success" role="status">
            <span aria-hidden="true">✓</span><div><?= $tc_escape($tc_notice[1]) ?></div>
        </div>
    <?php endif; ?>

    <?php if ($tc_data_error !== ''): ?>
        <div class="tc-alert tc-alert-error" role="alert">
            <span aria-hidden="true">!</span><div><?= $tc_escape($tc_data_error) ?></div>
        </div>
    <?php endif; ?>

    <?php if (!$tc_csrf_ready): ?>
        <div class="tc-alert tc-alert-info" role="status">
            <span aria-hidden="true">i</span>
            <div><strong>Ändringar är tillfälligt avstängda.</strong> En säker session kunde inte startas. Kontrollera att sessionen startas innan appen skriver ut sidans HTML.</div>
        </div>
    <?php endif; ?>

    <?php if ($tc_post_error !== ''): ?>
        <div class="tc-alert tc-alert-error" role="alert">
            <span aria-hidden="true">!</span><div><?= $tc_escape($tc_post_error) ?></div>
        </div>
    <?php endif; ?>

    <?php if ($tc_edit_not_found): ?>
        <div class="tc-alert tc-alert-info" role="status">
            <span aria-hidden="true">i</span><div><?= ($portalLanguage ?? 'sv') === 'en' ? 'Customer not found. You can add a new customer below.' : 'Kunden kunde inte hittas. Du kan lägga till en ny kund nedan.' ?></div>
        </div>
    <?php endif; ?>

    <section class="tc-panel tc-form-panel" aria-labelledby="tc-form-title" id="tc-customer-form">
        <div class="tc-panel-head">
            <div>
                <h2 class="tc-panel-title" id="tc-form-title"><?= $tc_form_is_edit ? 'Redigera kund' : 'Lägg till kund' ?></h2>
                <p class="tc-panel-help"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Start with a name and phone number. Other details are optional.' : 'Börja med namn och telefon. Övriga uppgifter är valfria.' ?></p>
            </div>
        </div>

        <form class="tc-form" method="post" autocomplete="on">
            <input type="hidden" name="action" value="save_customer">
            <input type="hidden" name="id" value="<?= $tc_escape($tc_form_id) ?>">
            <?php if ($tc_csrf_ready): ?>
                <input type="hidden" name="csrf_token" value="<?= $tc_escape($tc_csrf_token) ?>">
            <?php endif; ?>

            <?php if ($tc_duplicate_warning && $tc_duplicate_matches !== []): ?>
                <div class="tc-alert tc-alert-warning" role="alert" aria-live="assertive">
                    <span aria-hidden="true">⚠</span>
                    <div>
                        <strong><?= ($portalLanguage ?? 'sv') === 'en' ? 'Possible duplicates found' : 'Möjliga dubbletter hittades' ?></strong>
                        <p><?= ($portalLanguage ?? 'sv') === 'en' ? 'Nothing has been saved yet. Compare the records below. Change the details if the customer already exists; otherwise choose Save anyway to continue.' : 'Inget har sparats ännu. Jämför posterna nedan. Ändra uppgifterna om kunden redan finns; välj annars ”Spara ändå” för att fortsätta.' ?></p>
                        <ul class="tc-duplicate-matches">
                            <?php foreach ($tc_duplicate_matches as $tc_duplicate_match): ?>
                                <?php
                                    $tc_duplicate_name = trim($tc_duplicate_match['name'] ?? '');
                                    $tc_duplicate_fields = is_array($tc_duplicate_match['fields'] ?? null) ? $tc_duplicate_match['fields'] : [];
                                ?>
                                <li>
                                    <a href="index.php?tab=customers&amp;edit_id=<?= rawurlencode($tc_duplicate_match['id']) ?>#tc-customer-form" target="_blank" rel="noopener"><?= $tc_escape($tc_duplicate_name !== '' ? $tc_duplicate_name : 'Kund utan namn') ?></a>
                                    <span>— match på <?= $tc_escape(implode(', ', $tc_duplicate_fields)) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (isset($tc_form_errors['form'])): ?>
                <div class="tc-alert tc-alert-error" role="alert"><?= $tc_escape($tc_form_errors['form']) ?></div>
            <?php endif; ?>

            <div class="tc-fields">
                <div class="tc-field">
                    <label class="tc-label" for="tc-name"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Company / name' : 'Företag / namn' ?> <span class="tc-required" aria-label="obligatoriskt">*</span></label>
                    <input class="tc-input" id="tc-name" name="name" type="text" maxlength="160" required autocomplete="organization" placeholder="Exempel: Anna Andersson" value="<?= $tc_escape($tc_form_values['name'] ?? '') ?>" <?= isset($tc_form_errors['name']) ? 'aria-invalid="true" aria-describedby="tc-error-name"' : '' ?> <?= !$tc_actions_ready ? 'readonly' : '' ?>>
                    <?php if (isset($tc_form_errors['name'])): ?><p class="tc-field-error" id="tc-error-name"><?= $tc_escape($tc_form_errors['name']) ?></p><?php endif; ?>
                </div>
                <div class="tc-field">
                    <label class="tc-label" for="tc-phone"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Phone number' : 'Telefonnummer' ?> <span class="tc-required" aria-label="obligatoriskt">*</span></label>
                    <input class="tc-input" id="tc-phone" name="phone" type="tel" inputmode="tel" maxlength="32" required autocomplete="tel" placeholder="+46 70 123 45 67" value="<?= $tc_escape($tc_form_values['phone'] ?? '') ?>" <?= isset($tc_form_errors['phone']) ? 'aria-invalid="true" aria-describedby="tc-error-phone"' : '' ?> <?= !$tc_actions_ready ? 'readonly' : '' ?>>
                    <?php if (isset($tc_form_errors['phone'])): ?><p class="tc-field-error" id="tc-error-phone"><?= $tc_escape($tc_form_errors['phone']) ?></p><?php endif; ?>
                </div>
                <div class="tc-field">
                    <label class="tc-label" for="tc-email"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Email' : 'E-post' ?> <span class="tc-optional"><?= ($portalLanguage ?? 'sv') === 'en' ? 'optional' : 'valfritt' ?></span></label>
                    <input class="tc-input" id="tc-email" name="email" type="email" maxlength="254" autocomplete="email" placeholder="namn@exempel.se" value="<?= $tc_escape($tc_form_values['email'] ?? '') ?>" <?= isset($tc_form_errors['email']) ? 'aria-invalid="true" aria-describedby="tc-error-email"' : '' ?> <?= !$tc_actions_ready ? 'readonly' : '' ?>>
                    <?php if (isset($tc_form_errors['email'])): ?><p class="tc-field-error" id="tc-error-email"><?= $tc_escape($tc_form_errors['email']) ?></p><?php endif; ?>
                </div>
            </div>

            <details class="tc-extra" <?= $tc_extra_open ? 'open' : '' ?>>
                <summary><?= ($portalLanguage ?? 'sv') === 'en' ? 'More details' : 'Fler uppgifter' ?> <span class="tc-optional"><?= ($portalLanguage ?? 'sv') === 'en' ? 'optional' : 'valfritt' ?></span></summary>
                <div class="tc-fields tc-extra-grid">
                    <?php
                    $tc_form_references = is_array($tc_form_values['references'] ?? null)
                        ? array_values(array_slice(array_filter($tc_form_values['references'], 'is_string'), 0, 20))
                        : [];
                    $tc_reference_row_count = max(1, count($tc_form_references));
                    ?>
                    <div class="tc-field tc-field-wide">
                        <label class="tc-label" id="tc-references-label"><?= ($portalLanguage ?? 'sv') === 'en' ? 'References' : 'Referenser' ?> <span class="tc-optional">valfritt · flera går bra</span></label>
                        <div class="tc-reference-list" id="tc-reference-list" data-next-index="<?= $tc_reference_row_count ?>" aria-labelledby="tc-references-label">
                            <?php for ($tc_reference_index = 0; $tc_reference_index < $tc_reference_row_count; $tc_reference_index++): ?>
                                <?php $tc_reference_value = $tc_form_references[$tc_reference_index] ?? ''; ?>
                                <div class="tc-reference-row" data-reference-row>
                                    <label class="tc-visually-hidden" for="tc-reference-<?= $tc_reference_index ?>">Referens <?= $tc_reference_index + 1 ?></label>
                                    <input class="tc-input" id="tc-reference-<?= $tc_reference_index ?>" name="references[]" type="text" maxlength="120" autocomplete="off" placeholder="Kontaktperson, kundnummer eller annan referens" value="<?= $tc_escape($tc_reference_value) ?>" <?= isset($tc_form_errors['references']) ? 'aria-invalid="true" aria-describedby="tc-error-references"' : '' ?> <?= !$tc_actions_ready ? 'readonly' : '' ?>>
                                    <button class="tc-link-btn tc-reference-remove" type="button" data-remove-reference aria-label="Ta bort referens <?= $tc_reference_index + 1 ?>" <?= !$tc_actions_ready ? 'disabled' : '' ?>><?= ($portalLanguage ?? 'sv') === 'en' ? 'Remove' : 'Ta bort' ?></button>
                                </div>
                            <?php endfor; ?>
                        </div>
                        <button class="tc-btn tc-btn-secondary tc-add-reference" id="tc-add-reference" type="button" <?= !$tc_actions_ready || $tc_reference_row_count >= 20 ? 'disabled' : '' ?>><?= ($portalLanguage ?? 'sv') === 'en' ? '+ Add reference' : '+ Lägg till referens' ?></button>
                        <p class="tc-help"><?= ($portalLanguage ?? 'sv') === 'en' ? 'References are saved separately. Choose the appropriate one when quick-filling an invoice record.' : 'Referenserna sparas var för sig. Välj den som passar när du snabbfyller ett fakturaunderlag.' ?></p>
                        <?php if (isset($tc_form_errors['references'])): ?><p class="tc-field-error" id="tc-error-references"><?= $tc_escape($tc_form_errors['references']) ?></p><?php endif; ?>
                    </div>
                    <div class="tc-field">
                        <label class="tc-label" for="tc-personal-id"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Personal / company identification number' : 'Person-/organisationsnummer' ?> <span class="tc-optional"><?= ($portalLanguage ?? 'sv') === 'en' ? 'optional' : 'valfritt' ?></span></label>
                        <input class="tc-input" id="tc-personal-id" name="personal_id" type="text" maxlength="40" autocomplete="off" spellcheck="false" placeholder="Fyll bara i om det behövs" value="<?= $tc_escape($tc_form_values['personal_id'] ?? '') ?>" <?= isset($tc_form_errors['personal_id']) ? 'aria-invalid="true" aria-describedby="tc-error-personal-id"' : '' ?> <?= !$tc_actions_ready ? 'readonly' : '' ?>>
                        <?php if (isset($tc_form_errors['personal_id'])): ?><p class="tc-field-error" id="tc-error-personal-id"><?= $tc_escape($tc_form_errors['personal_id']) ?></p><?php endif; ?>
                    </div>
                    <div class="tc-field">
                        <label class="tc-label" for="tc-property-id"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Property designation' : 'Fastighetsbeteckning' ?> <span class="tc-optional"><?= ($portalLanguage ?? 'sv') === 'en' ? 'optional' : 'valfritt' ?></span></label>
                        <input class="tc-input" id="tc-property-id" name="property_id" type="text" maxlength="120" autocomplete="off" placeholder="Exempel: Staden 1:23" value="<?= $tc_escape($tc_form_values['property_id'] ?? '') ?>" <?= isset($tc_form_errors['property_id']) ? 'aria-invalid="true" aria-describedby="tc-error-property-id"' : '' ?> <?= !$tc_actions_ready ? 'readonly' : '' ?>>
                        <?php if (isset($tc_form_errors['property_id'])): ?><p class="tc-field-error" id="tc-error-property-id"><?= $tc_escape($tc_form_errors['property_id']) ?></p><?php endif; ?>
                    </div>
                </div>
                <p class="tc-privacy-note"><span aria-hidden="true">🔒</span><span><?= ($portalLanguage ?? 'sv') === 'en' ? 'Personal and company identification numbers are sensitive information. Enter them only when the business needs them.' : 'Person- och organisationsnummer är känsliga uppgifter. Registrera dem bara när verksamheten faktiskt behöver dem.' ?></span></p>
            </details>

            <div class="tc-form-actions">
                <button class="tc-btn <?= $tc_duplicate_warning ? 'tc-btn-secondary' : 'tc-btn-primary' ?>" type="submit" <?= $tc_duplicate_warning ? '' : 'data-mobile-action-dock' ?> <?= !$tc_actions_ready ? 'disabled' : '' ?>>
                    <?= $tc_duplicate_warning ? 'Kontrollera igen' : ($tc_form_is_edit ? 'Spara ändringar' : 'Lägg till kund') ?>
                </button>
                <?php if ($tc_duplicate_warning): ?>
                    <button class="tc-btn tc-btn-primary" type="submit" name="confirm_possible_duplicates" value="1" data-mobile-action-dock <?= !$tc_actions_ready ? 'disabled' : '' ?>><?= ($portalLanguage ?? 'sv') === 'en' ? 'Save anyway' : 'Spara ändå' ?></button>
                <?php endif; ?>
                <?php if ($tc_form_is_edit): ?>
                    <a class="tc-btn tc-btn-secondary" href="index.php?tab=customers"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Cancel' : 'Avbryt' ?></a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <section aria-labelledby="tc-list-title">
        <div class="tc-list-head">
            <div>
                <h2 class="tc-list-title" id="tc-list-title"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Customer list' : 'Kundlista' ?></h2>
                <p class="tc-list-subtitle"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Search names, phone numbers, email addresses and references.' : 'Sök bland namn, telefon, e-post och referens.' ?></p>
                <div class="tc-count-badge" aria-label="Totalt antal kunder">
                    <strong><?= count($tc_customers) ?></strong>
                    <?= count($tc_customers) === 1 ? 'kund sparad' : 'kunder sparade' ?>
                </div>
            </div>
            <label class="tc-search-wrap">
                <span class="tc-visually-hidden"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Search customers' : 'Sök kunder' ?></span>
                <svg class="tc-search-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.8" stroke="currentColor" stroke-width="1.8"/><path d="m16 16 4.2 4.2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                <input class="tc-search" id="tc-customer-search" type="search" placeholder="Sök kunder…" autocomplete="off" aria-controls="tc-customer-list">
            </label>
        </div>

        <p class="tc-results-meta" aria-live="polite"><span id="tc-visible-count"><?= count($tc_customers) ?></span> av <?= count($tc_customers) ?> kunder visas</p>

        <div class="tc-customer-list" id="tc-customer-list">
            <?php foreach ($tc_customers as $tc_customer): ?>
                <?php
                    $tc_search_values = [
                        $tc_customer['name'],
                        $tc_customer['phone'],
                        $tc_customer['email'],
                        $tc_customer['property_id'],
                    ];
                    foreach (is_array($tc_customer['references'] ?? null) ? $tc_customer['references'] : [] as $tc_reference) {
                        $tc_search_values[] = $tc_reference;
                    }
                    $tc_search_text = implode(' ', array_filter($tc_search_values, static function (mixed $value): bool {
                        return $value !== '';
                    }));
                    $tc_tel = preg_replace('/[^0-9+]/', '', $tc_customer['phone']);
                ?>
                <article class="tc-customer-card" data-customer-card data-search="<?= $tc_escape($tc_search_text) ?>">
                    <div class="tc-customer-main">
                        <h3 class="tc-customer-name"><?= $tc_escape($tc_customer['name']) ?></h3>
                        <div class="tc-customer-contact">
                            <?php if ($tc_customer['phone'] !== ''): ?>
                                <a class="tc-contact-item" href="tel:<?= $tc_escape($tc_tel) ?>" style="color:inherit;text-decoration:none">
                                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M7.2 3.5h2.1l1.1 4.2-1.7 1.5a14.3 14.3 0 0 0 6.1 6.1l1.5-1.7 4.2 1.1v2.1c0 1.2-1 2.2-2.2 2.2A15.8 15.8 0 0 1 5 5.7c0-1.2 1-2.2 2.2-2.2Z" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    <?= $tc_escape($tc_customer['phone']) ?>
                                </a>
                            <?php endif; ?>
                            <?php if ($tc_customer['email'] !== ''): ?>
                                <span class="tc-contact-item">
                                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3.5" y="5" width="17" height="14" rx="2" stroke="currentColor" stroke-width="1.7"/><path d="m4.5 7 7.5 5.5L19.5 7" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    <?= $tc_escape($tc_customer['email']) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($tc_customer['references']) || $tc_customer['property_id'] !== ''): ?>
                            <div class="tc-tag-row">
                                <?php foreach (is_array($tc_customer['references'] ?? null) ? $tc_customer['references'] : [] as $tc_reference): ?>
                                    <span class="tc-tag">Referens: <?= $tc_escape($tc_reference) ?></span>
                                <?php endforeach; ?>
                                <?php if ($tc_customer['property_id'] !== ''): ?><span class="tc-tag">Fastighet: <?= $tc_escape($tc_customer['property_id']) ?></span><?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="tc-card-actions">
                        <a class="tc-link-btn" href="index.php?tab=customers&amp;edit_id=<?= rawurlencode($tc_customer['id']) ?>"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Edit' : 'Redigera' ?></a>
                        <form class="tc-delete-form" method="post" onsubmit="return confirm('Vill du verkligen radera den här kunden?');">
                            <input type="hidden" name="action" value="delete_customer">
                            <input type="hidden" name="id" value="<?= $tc_escape($tc_customer['id']) ?>">
                            <?php if ($tc_csrf_ready): ?><input type="hidden" name="csrf_token" value="<?= $tc_escape($tc_csrf_token) ?>"><?php endif; ?>
                            <button class="tc-link-btn tc-link-danger" type="submit" <?= !$tc_actions_ready ? 'disabled' : '' ?>><?= ($portalLanguage ?? 'sv') === 'en' ? 'Delete' : 'Radera' ?></button>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <div class="tc-empty" id="tc-no-customers" <?= count($tc_customers) > 0 ? 'hidden' : '' ?>>
            <div class="tc-empty-icon" aria-hidden="true">＋</div>
            <h3><?= ($portalLanguage ?? 'sv') === 'en' ? 'No customers yet' : 'Inga kunder ännu' ?></h3>
            <p><?= ($portalLanguage ?? 'sv') === 'en' ? 'Add the first customer using the form above. A name and phone number are enough to get started.' : 'Lägg till den första kunden i formuläret ovan. Det räcker med namn och telefonnummer för att komma igång.' ?></p>
            <a class="tc-btn tc-btn-primary" href="#tc-customer-form"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Add first customer' : 'Lägg till första kunden' ?></a>
        </div>
        <div class="tc-empty" id="tc-no-results" hidden>
            <div class="tc-empty-icon" aria-hidden="true">⌕</div>
            <h3><?= ($portalLanguage ?? 'sv') === 'en' ? 'No results' : 'Inga träffar' ?></h3>
            <p><?= ($portalLanguage ?? 'sv') === 'en' ? 'Try another name, phone number or search.' : 'Prova ett annat namn, telefonnummer eller en annan sökning.' ?></p>
            <button class="tc-btn tc-btn-secondary" id="tc-clear-search" type="button"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Clear search' : 'Rensa sökning' ?></button>
        </div>
    </section>
</section>

<script>
(function () {
    'use strict';
    var search = document.getElementById('tc-customer-search');
    var cards = Array.prototype.slice.call(document.querySelectorAll('[data-customer-card]'));
    var visibleCount = document.getElementById('tc-visible-count');
    var noCustomers = document.getElementById('tc-no-customers');
    var noResults = document.getElementById('tc-no-results');
    var clearButton = document.getElementById('tc-clear-search');
    var customerForm = document.querySelector('.tc-form');
    var customerSaveButton = customerForm ? customerForm.querySelector('.tc-form-actions .tc-btn-primary[type="submit"]') : null;
    var customerSaveMedia = typeof window.matchMedia === 'function' ? window.matchMedia('(max-width:560px), (max-width:900px) and (max-height:560px) and (orientation:landscape)') : null;

    function updateMobileSaveDock() {
        if (!customerForm || !customerSaveButton || !customerSaveMedia) return;
        var formRect = customerForm.getBoundingClientRect();
        var formVisible = formRect.bottom > 0 && formRect.top < window.innerHeight;
        var viewport = window.visualViewport;
        var keyboardOpen = !!(viewport && viewport.height < window.innerHeight * 0.72);
        customerSaveButton.classList.toggle('tc-mobile-fixed', customerSaveMedia.matches && formVisible && !keyboardOpen);
    }

    if (customerSaveButton && customerSaveMedia) {
        window.addEventListener('scroll', updateMobileSaveDock, { passive: true });
        window.addEventListener('resize', updateMobileSaveDock);
        if (window.visualViewport) window.visualViewport.addEventListener('resize', updateMobileSaveDock);
        if (typeof customerSaveMedia.addEventListener === 'function') customerSaveMedia.addEventListener('change', updateMobileSaveDock);
        else if (typeof customerSaveMedia.addListener === 'function') customerSaveMedia.addListener(updateMobileSaveDock);
        updateMobileSaveDock();
    }

    if (!search) return;

    function normalize(value) {
        var text = String(value || '').toLocaleLowerCase('sv-SE');
        if (typeof text.normalize === 'function') {
            text = text.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        }
        return text.trim();
    }

    function filterCustomers() {
        var query = normalize(search.value);
        var visible = 0;
        cards.forEach(function (card) {
            var match = normalize(card.getAttribute('data-search')).indexOf(query) !== -1;
            card.hidden = !match;
            if (match) visible += 1;
        });
        if (visibleCount) visibleCount.textContent = String(visible);
        if (noCustomers) noCustomers.hidden = cards.length !== 0;
        if (noResults) noResults.hidden = cards.length === 0 || visible !== 0;
    }

    search.addEventListener('input', filterCustomers);
    if (clearButton) {
        clearButton.addEventListener('click', function () {
            search.value = '';
            filterCustomers();
            search.focus();
        });
    }
    filterCustomers();

    var referenceList = document.getElementById('tc-reference-list');
    var addReferenceButton = document.getElementById('tc-add-reference');
    if (referenceList && addReferenceButton) {
        var referenceActionsReady = !referenceList.querySelector('[data-remove-reference]').disabled;
        var nextReferenceIndex = parseInt(referenceList.getAttribute('data-next-index') || '0', 10);

        function updateReferenceButtons() {
            var rows = Array.prototype.slice.call(referenceList.querySelectorAll('[data-reference-row]'));
            addReferenceButton.disabled = !referenceActionsReady || rows.length >= 20;
            rows.forEach(function (row) {
                var removeButton = row.querySelector('[data-remove-reference]');
                if (removeButton) removeButton.disabled = !referenceActionsReady || rows.length <= 1;
            });
        }

        addReferenceButton.addEventListener('click', function () {
            var rows = referenceList.querySelectorAll('[data-reference-row]');
            if (!referenceActionsReady || rows.length >= 20) return;

            var index = nextReferenceIndex++;
            var row = document.createElement('div');
            row.className = 'tc-reference-row';
            row.setAttribute('data-reference-row', '');

            var label = document.createElement('label');
            label.className = 'tc-visually-hidden';
            label.htmlFor = 'tc-reference-' + index;
            label.textContent = 'Referens ' + (index + 1);

            var input = document.createElement('input');
            input.className = 'tc-input';
            input.id = 'tc-reference-' + index;
            input.name = 'references[]';
            input.type = 'text';
            input.maxLength = 120;
            input.autocomplete = 'off';
            input.placeholder = 'Kontaktperson, kundnummer eller annan referens';

            var removeButton = document.createElement('button');
            removeButton.className = 'tc-link-btn tc-reference-remove';
            removeButton.type = 'button';
            removeButton.setAttribute('data-remove-reference', '');
            removeButton.setAttribute('aria-label', 'Ta bort referens ' + (index + 1));
            removeButton.textContent = 'Ta bort';

            row.appendChild(label);
            row.appendChild(input);
            row.appendChild(removeButton);
            referenceList.appendChild(row);
            updateReferenceButtons();
            input.focus();
        });

        referenceList.addEventListener('click', function (event) {
            var removeButton = event.target.closest('[data-remove-reference]');
            if (!removeButton || !referenceActionsReady) return;
            var row = removeButton.closest('[data-reference-row]');
            if (!row) return;
            var rows = referenceList.querySelectorAll('[data-reference-row]');
            if (rows.length <= 1) {
                var onlyInput = row.querySelector('input[name="references[]"]');
                if (onlyInput) onlyInput.value = '';
            } else {
                row.remove();
            }
            updateReferenceButtons();
        });

        updateReferenceButtons();
    }
})();
</script>

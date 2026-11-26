<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/release_updater.php';
require_once __DIR__ . '/../../includes/github_release_updater.php';

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="test-token">';
}

function portal_central_admin_auth_setup_state(): string
{
    return 'unconfigured';
}

function portal_email_transport_status(): array
{
    return [
        'transport' => 'smtp',
        'configured' => true,
        'tested' => true,
        'host' => 'smtp-secret.example.test',
        'username' => 'private-user@example.test',
        'from_email' => 'private-sender@example.test',
        'password' => 'must-not-be-rendered',
    ];
}

function portal_email_status_read(): ?array
{
    return [
        'invoice' => ['events' => [['at' => '2026-10-06T12:00:00+02:00', 'kind' => 'test', 'result' => 'accepted', 'transport' => 'smtp', 'code' => 'accepted']]],
        'skadeblankett' => ['events' => []],
    ];
}

function render_admin_panel_for_test(string $setupState, ?array $centralUser, ?array $invoiceUser, string $invoiceSetupState, ?array $releaseResult = null): string
{
    $centralAdminSetupState = $setupState;
    $centralAdminUser = $centralUser;
    $invoiceAuthUser = $invoiceUser;
    $portalReleaseResult = $releaseResult;
    $csrfReady = true;
    ob_start();
    include __DIR__ . '/../../tabs/admin.php';
    return (string) ob_get_clean();
}

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$invoiceAdmin = [
    'id' => 'existing-invoice-admin',
    'username' => 'existing-admin',
    'name' => 'Existing admin',
    'role' => 'admin',
    'active' => true,
];
$invoiceStaff = [
    'id' => 'existing-invoice-staff',
    'username' => 'existing-staff',
    'name' => 'Existing staff',
    'role' => 'staff',
    'active' => true,
];

$firstSetup = render_admin_panel_for_test('unconfigured', null, $invoiceAdmin, 'configured');
$assert(str_contains($firstSetup, 'central_admin_enroll') && str_contains($firstSetup, 'Välj central admin-PIN'), 'Only the existing invoice admin should see the first central-PIN setup form.');

$staffSetup = render_admin_panel_for_test('unconfigured', null, $invoiceStaff, 'configured');
    $assert(!str_contains($staffSetup, 'central_admin_enroll') && str_contains($staffSetup, 'faktura-adminkonto'), 'Invoice staff must be guided to the existing admin and must not receive the setup form.');

$firstInvoiceAccount = render_admin_panel_for_test('unconfigured', null, null, 'unconfigured');
$assert(str_contains($firstInvoiceAccount, '?tab=invoice') && !str_contains($firstInvoiceAccount, 'central_admin_enroll'), 'First invoice-admin setup must precede central-PIN enrollment when no account exists.');

$login = render_admin_panel_for_test('configured', null, null, 'configured');
$assert(str_contains($login, 'central_admin_login') && str_contains($login, 'SFTP-återställning'), 'Configured central auth should show the central login and SFTP recovery instructions.');
$assert(str_contains($login, 'Central admin-PIN-kod') && str_contains($login, 'autocomplete="off"') && !str_contains($login, 'current-password'), 'The central login should be presented as a PIN-only field, not a browser password-manager field.');
$assert(str_contains($login, 'äldre PIN-kod') && str_contains($login, '6–10 siffror'), 'The login screen should explain the new PIN rule and one-time legacy migration.');
$assert(!str_contains($login, 'Driftshälsa'), 'The health check must not render before central-admin authentication.');
$assert(!str_contains($login, 'GitHub-releasekontroll') && !str_contains($login, 'portal_release_check'), 'Release status and actions must remain hidden before central-admin authentication.');

$activeUser = [
    'id' => 'central-admin',
    'username' => 'central-admin',
    'name' => 'Central administratör',
    'role' => 'admin',
    'active' => true,
    'auth_source' => 'central_admin',
    'must_change_pin' => false,
    'pin_changed_at' => time(),
];
$active = render_admin_panel_for_test('configured', $activeUser, null, 'configured');
$assert(str_contains($active, 'central_admin_change_pin') && str_contains($active, 'Global sökning') && str_contains($active, 'Kundregister'), 'An active central admin should see all staff-page links and the PIN-change control.');
$assert(str_contains($active, 'Driftshälsa') && str_contains($active, 'PDF-arkivets rot') && str_contains($active, 'E-postkonfiguration') && str_contains($active, 'Senaste e-posttest'), 'An authenticated central admin should see the PDF, storage, and email health checks.');
$assert(!str_contains($active, 'smtp-secret.example.test') && !str_contains($active, 'private-user@example.test') && !str_contains($active, 'private-sender@example.test') && !str_contains($active, 'must-not-be-rendered'), 'The health check must not render SMTP host, account, sender, or password values.');
$assert(!str_contains($active, dirname(__DIR__, 2)), 'The health check must not render absolute server filesystem paths.');
$assert(!str_contains($active, 'central_admin_login"'), 'An authenticated central admin should not be shown the login form.');
$assert(str_contains($active, '6–10 siffror') && str_contains($active, 'högst 2 valfria specialtecken') && str_contains($active, 'minlength="6" maxlength="12"'), 'PIN enrollment and rotation should explain and constrain the numeric PIN format.');
$assert(!str_contains($active, 'new-password') && !str_contains($active, 'current-password') && !str_contains($active, 'inga lösenord'), 'The central PIN interface must not be described or autofilled as a password.');
$assert(str_contains($active, 'GitHub-releasekontroll') && str_contains($active, 'Aktiv'), 'Only authenticated central admins should see GitHub release status.');
$assert(str_contains($active, 'Ingen separat signatur kontrolleras') && str_contains($active, 'Tokke2/document'), 'The admin panel explains the unsigned GitHub trust boundary.');
$assert(str_contains($active, 'Ett knapptryck kontrollerar och installerar') && str_contains($active, 'Ta en separat backup') && str_contains($active, 'utan att först visa en fillista'), 'The one-click workflow and risks are clearly explained.');
$assert(str_contains($active, 'name="action" value="portal_release_install_latest"') && !str_contains($active, 'name="confirm_install"'), 'One explicit button initiates the update without a checkbox.');
$failed = render_admin_panel_for_test('configured', $activeUser, null, 'configured', ['ok' => false, 'code' => 'release_changed_since_approval', 'action' => 'install']);
$assert(str_contains($failed, 'GitHub ändrade releasen under kontrollen') && str_contains($failed, 'name="action" value="portal_release_install_latest"'), 'A changed release is reported and the update can be retried.');
$critical = render_admin_panel_for_test('configured', $activeUser, null, 'configured', ['ok' => false, 'code' => 'rollback_failed', 'action' => 'install']);
$assert(str_contains($critical, 'Återställningen misslyckades') && !str_contains($critical, 'name="action" value="portal_release_install_latest"'), 'The update button is hidden when rollback fails.');

$controllerSource = file_get_contents(__DIR__ . '/../../index.php');
$csrfPosition = is_string($controllerSource) ? strpos($controllerSource, 'if (!csrf_is_valid())') : false;
$releaseActionPosition = is_string($controllerSource) ? strpos($controllerSource, "if (in_array(\$action, ['portal_release_check', 'portal_release_install', 'portal_release_install_latest'], true))") : false;
$releaseBranchEnd = is_string($controllerSource) && is_int($releaseActionPosition)
    ? strpos($controllerSource, "} elseif (\$action === 'portal_search')", $releaseActionPosition)
    : false;
$assert(is_string($controllerSource) && is_int($csrfPosition) && is_int($releaseActionPosition) && $csrfPosition < $releaseActionPosition, 'Release POST handlers must run only after the shared CSRF check.');
$releaseBranch = is_string($controllerSource) && is_int($releaseActionPosition) && is_int($releaseBranchEnd)
    ? substr($controllerSource, $releaseActionPosition, $releaseBranchEnd - $releaseActionPosition)
    : '';
$assert(str_contains($releaseBranch, "\$activeTab !== 'admin' || \$portalIsScopedPwa"), 'Release endpoints must be confined to the full Adminpanel route, not scoped PWAs.');
$assert(str_contains($releaseBranch, '!$centralAdminCanAccess') && str_contains($releaseBranch, 'is_array($centralAdminUser)'), 'Release endpoints must require an authenticated central admin.');
$assert(str_contains($releaseBranch, "\$action === 'portal_release_install_latest'") && str_contains($releaseBranch, 'portal_github_release_install_latest(__DIR__)'), 'One-click installation must be explicitly requested on the protected POST route.');
$assert(str_contains($controllerSource, "str_starts_with(\$msgKey, 'release_') && (\$activeTab !== 'admin' || !\$centralAdminCanAccess)"), 'Release notices must be hidden outside the authenticated Adminpanel.');

$expiredUser = $activeUser;
$expiredUser['must_change_pin'] = true;
$expiredUser['pin_policy_update_required'] = false;
$expired = render_admin_panel_for_test('configured', $expiredUser, null, 'configured');
$assert(str_contains($expired, 'PIN-byte krävs') && str_contains($expired, 'central_admin_change_pin') && str_contains($expired, 'Det har gått 20 dagar'), 'An overdue central PIN should explain the age-based rotation requirement.');
$assert(!str_contains($expired, 'Driftshälsa'), 'The health check must remain hidden until an overdue admin PIN has been changed.');
$legacyPolicyUser = $expiredUser;
$legacyPolicyUser['pin_policy_update_required'] = true;
$policyUpdate = render_admin_panel_for_test('configured', $legacyPolicyUser, null, 'configured');
$assert(str_contains($policyUpdate, 'PIN-kod följer ett äldre format') && str_contains($policyUpdate, '6–10 siffror'), 'Legacy PIN users should be told why rotation is required and shown the new format.');

$indexSource = (string) file_get_contents(__DIR__ . '/../../index.php');
$assert(str_contains($indexSource, 'header-admin-btn') && str_contains($indexSource, "'?tab=admin'"), 'Central admin gets a permanent header shortcut to the Adminpanel.');
$assert(str_contains($indexSource, 'portal_github_release_marker_differs') && str_contains($indexSource, '#central-admin-release-title'), 'The shortcut offers a direct update link when a newer release was checked.');
$cssSource = (string) file_get_contents(__DIR__ . '/../../assets/portal.css');
$assert(str_contains($cssSource, '.header-admin-btn') && str_contains($cssSource, '.header-admin-btn.is-update'), 'The admin shortcut has header styling and a distinct update state.');
$assert(str_contains($indexSource, 'portal_github_release_auto_update_if_due') && str_contains($indexSource, '($_SERVER[\'REQUEST_METHOD\'] ?? \'GET\') === \'GET\''), 'A full-portal GET auto-updates from git at most once per hour.');
$assert(str_contains($indexSource, 'portal_logout_all') && str_contains($indexSource, 'header-logout-btn'), 'A header logout button ends all portal sessions.');

// Version number under Inställningar (release constant + visible runtime strip).
$versionSource = (string) file_get_contents(__DIR__ . '/../../includes/portal_version.php');
$settingsSource = (string) file_get_contents(__DIR__ . '/../../tabs/settings.php');
$assert(preg_match('/const TADEGA_PORTAL_VERSION = \'[0-9]+\.[0-9]+\.[0-9]+\';/', $versionSource) === 1, 'Release constant carries a semantic version.');
$assert(str_contains($indexSource, 'TADEGA_PORTAL_VERSION') && str_contains($indexSource, 'portal_github_release_install_marker(__DIR__)'), 'Version label prefers the installed release marker.');
$assert(str_contains($settingsSource, 'Version <?= h($portalVersionLabel') && str_contains($settingsSource, 'settings-runtime-version'), 'Inställningar always shows the portal version in the runtime strip.');

// Central admin needs no second sign-in on staff pages (invoice + protocol
// already fall back to the central admin session).
$invoiceAuthSource = (string) file_get_contents(__DIR__ . '/../../includes/invoice_auth.php');
$assert(str_contains($invoiceAuthSource, 'portal_central_admin_auth_portal_user'), 'Invoice session falls back to the signed-in central admin.');
$assert(str_contains($indexSource, 'portal_central_admin_auth_portal_user'), 'Central admin sign-in covers search and staff tabs.');

// Customer register: everyone may add, only the admin role may delete.
$customersSource = (string) file_get_contents(__DIR__ . '/../../tabs/customers.php');
$assert(str_contains($customersSource, '$tc_can_delete = ($invoiceAuthUser[\'role\'] ?? \'\') === \'admin\''), 'Customer deletion is restricted to the admin role.');
$assert(str_contains($customersSource, 'Only admins can delete customers.') && str_contains($customersSource, 'if ($tc_can_delete):'), 'Delete guard blocks the action and hides the button for staff.');

// Editable print logo: downloadable, live text, palette documented.
$printLogoSource = (string) file_get_contents(__DIR__ . '/../../assets/tadega-logo-tryck.svg');
$assert(str_contains($settingsSource, 'tadega-logo-tryck.svg'), 'Settings offers the editable print SVG for download.');
$assert(str_contains($printLogoSource, '<text id="namn"') && str_contains($printLogoSource, 'Palett:'), 'Print original keeps editable text objects and a palette note.');
$assert(str_contains($indexSource, '\'tadega-logo-tryck.svg\''), 'Print original passes the asset allowlist.');

// Customer register is an invoice sub-view; the standalone tab is gone.
$invoiceTabSource = (string) file_get_contents(__DIR__ . '/../../tabs/invoice.php');
$assert(!str_contains($indexSource, "'customers' => ['Kundregister'"), 'Kundregister is no longer a top-level tab.');
$assert(str_contains($indexSource, "if (\$activeTab === 'customers')") && str_contains($indexSource, "\$_GET['invoice_view'] = 'customers'"), 'Legacy ?tab=customers links rewrite to the invoice sub-view.');
$assert(str_contains($invoiceTabSource, "require __DIR__ . '/customers.php';"), 'The Kunder sub-view renders the full customer register.');
$assert(str_contains($invoiceTabSource, 'Customer register') && str_contains($invoiceTabSource, 'invoice_view=customers'), 'Invoice sub-tab is labelled Kundregister and links to itself.');

// Service report item register: own sub-view, own storage file, admin-gated.
$protocolSource = (string) file_get_contents(__DIR__ . '/../../tabs/protocol.php');
$protocolArticlesSource = (string) file_get_contents(__DIR__ . '/../../tabs/protocol_articles.php');
$protocolCatalogSource = (string) file_get_contents(__DIR__ . '/../../includes/protocol_catalog.php');
$invoiceCatalogSource = (string) file_get_contents(__DIR__ . '/../../includes/invoice_catalog.php');
$assert(str_contains($protocolSource, "requestedProtocolView === 'articles'") && str_contains($protocolSource, 'protocol_articles.php'), 'Service reports expose an Artikelregister sub-view.');
$assert(substr_count($protocolSource, 'protocol_view=articles') >= 2, 'Both service report sub-navs link the item register.');
$assert(str_contains($protocolArticlesSource, 'protocol_catalog_add') && str_contains($protocolArticlesSource, 'protocol_catalog_delete'), 'Item register uses the add and delete form actions.');
$assert(str_contains($indexSource, '$action === \'protocol_catalog_add\'') && str_contains($indexSource, 'protocol_catalog_admin_required'), 'Item register writes require a service report administrator.');
$assert(str_contains($protocolCatalogSource, 'protocol_articles.json') && str_contains($invoiceCatalogSource, 'invoice_articles.json'), 'The two item registers store articles separately.');
$assert(str_contains($indexSource, 'protocol_catalog_saved') && str_contains($indexSource, 'protocol_catalog_deleted'), 'Item register notices exist in Swedish and English.');

fwrite(STDOUT, 'PASS: ' . $checks . ' Adminpanel render checks.' . PHP_EOL);

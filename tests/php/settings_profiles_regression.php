<?php
declare(strict_types=1);

// The Settings "Profiler" sub-tab must only offer apps with their own profile
// systems, only for invoice admins, and must reuse the existing server
// actions instead of a parallel storage path.
$count = 0;
$assert = static function (bool $ok, string $msg) use (&$count): void {
    $count++;
    if (!$ok) {
        throw new RuntimeException("check {$count}: {$msg}");
    }
};

$settings = (string) file_get_contents(__DIR__ . '/../../tabs/settings.php');
$index = (string) file_get_contents(__DIR__ . '/../../index.php');

$assert(str_contains($settings, "sub=profiler"), 'Settings exposes the Profiler sub-tab URL');
$assert(str_contains($settings, '$settingsSub === \'profiler\''), 'Sub-view is driven by the sub parameter');
$assert(str_contains($settings, '$settingsIsProfileAdmin'), 'Sub-tab is gated behind invoice admin');
$assert(str_contains($settings, 'if ($settingsProfileApps === [])') || str_contains($settings, '$settingsProfileApps !== []'), 'Sub-nav only renders when profile apps exist');

// Only apps with their own profile systems are offered.
$assert(str_contains($settings, "\$settingsProfileApps[] = 'invoice';"), 'Invoice records offer profiles when configured');
$assert(str_contains($settings, "\$settingsProfileApps[] = 'protocol';"), 'Service reports offer profiles');
foreach (['calendar', 'skadeblankett', 'files', 'archive', 'search'] as $noProfileApp) {
    $assert(!str_contains($settings, "\$settingsProfileApps[] = '$noProfileApp';"), "App without own profiles is not offered: {$noProfileApp}");
}

// Forms reuse the existing protected actions and field names.
$assert(str_contains($settings, 'value="invoice_admin_create_user"') && str_contains($index, "'invoice_admin_create_user'"), 'Invoice create action exists and is allowed');
$assert(str_contains($settings, 'value="protocol_admin_create_user"') && str_contains($index, "'protocol_admin_create_user'"), 'Protocol create action exists and is allowed');
$assert(str_contains($settings, 'name="user[username]"') && str_contains($settings, 'name="user[pin]"'), 'Invoice form posts the established user fields');
$assert(str_contains($settings, 'name="protocol_user[username]"') && str_contains($settings, 'name="protocol_user[pin]"'), 'Protocol form posts the established protocol_user fields');
$assert(substr_count($settings, 'csrf_field()') >= 3, 'Every profile form carries a CSRF token');
$assert(str_contains($settings, 'portal_invoice_auth_admin_users()') && str_contains($settings, 'portal_protocol_auth_admin_users()'), 'Existing profiles are listed through the existing readers');
$assert(!str_contains($settings, 'active_errors') , 'Settings does not touch unrelated storage');

echo "PASS: settings profiles sub-tab regression ({$count} checks)\n";

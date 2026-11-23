<?php
declare(strict_types=1);
$root = __DIR__ . '/.calendar-render-sandbox'; @mkdir($root); @mkdir($root . '/includes'); @mkdir($root . '/storage');
copy(__DIR__ . '/../../functions.php', $root . '/functions.php');
foreach (['calendar.php','logger.php','email_transport.php'] as $f) copy(__DIR__ . '/../../includes/' . $f, $root . '/includes/' . $f);
file_put_contents($root . '/storage/.htaccess', "Require all denied\n");
require $root . '/functions.php'; require $root . '/includes/calendar.php';
$a=str_repeat('a',32); $b=str_repeat('b',32); $c=str_repeat('c',32);
$GLOBALS['test_user']=['id'=>$b, 'name'=>'Reader'];
function portal_invoice_auth_personal_user(): ?array { return $GLOBALS['test_user']; }
function portal_invoice_auth_read_users(): array { return [['id'=>str_repeat('a',32),'name'=>'Owner','role'=>'staff','active'=>true],['id'=>str_repeat('b',32),'name'=>'Reader','role'=>'staff','active'=>true]]; }
$base=['notes'=>'', 'date'=>date('Y-m-d'), 'start_time'=>'08:00','end_time'=>'','status'=>'planned'];
portal_calendar_update($a, 'save', $base+['title'=>'SECRET-PRIVATE'], [$b]);
portal_calendar_update($a, 'save', $base+['title'=>'SHARED-VISIBLE','shared_with'=>[$b]], [$b]);
portal_calendar_update($c, 'save', $base+['title'=>'SECRET-OTHER'], [$b]);
$portalLanguage = 'en'; $_GET=[]; ob_start(); include __DIR__ . '/../../tabs/calendar.php'; $html=ob_get_clean();
$n=0; $assert=static function(bool $ok,string $message)use(&$n):void{$n++;if(!$ok)throw new RuntimeException($message);};
$assert(str_contains($html,'SHARED-VISIBLE') && !str_contains($html,'SECRET-PRIVATE') && !str_contains($html,'SECRET-OTHER'), 'Only shared records rendered');
$assert(str_contains($html,'Shared with you · read-only') && !str_contains($html,'calendar_delete'), 'Read-only recipient has no delete action');
$assert(str_contains($html,'Daily overview') && str_contains($html,'Scheduled and shared work'), 'English calendar rendering');
$portalLanguage='sv'; ob_start(); include __DIR__ . '/../../tabs/calendar.php'; $sv=ob_get_clean();
$assert(str_contains($sv,'Dagsöversikt') && str_contains($sv,'Delat med dig'), 'Swedish rendering');
$GLOBALS['test_user']=['id'=>$a, 'name'=>'Owner']; $portalLanguage='sv';
ob_start(); include __DIR__ . '/../../tabs/calendar.php'; $owner=ob_get_clean();
$assert(str_contains($owner,'calendar-event-actions') && str_contains($owner,'name="action" value="calendar_delete"') && str_contains($owner,'calendar-planner'), 'Owner controls and responsive form remain available');
$assert(str_contains($owner,'calendar-heading-icon') && str_contains($owner,'aria-hidden="true"'), 'Calendar icons are decorative');
foreach (glob($root . '/storage/*') ?: [] as $f) unlink($f); unlink($root . '/storage/.htaccess'); rmdir($root . '/storage');
foreach (glob($root . '/includes/*') ?: [] as $f) unlink($f); rmdir($root . '/includes'); unlink($root . '/functions.php'); rmdir($root);
echo "PASS: $n calendar render checks.\n";

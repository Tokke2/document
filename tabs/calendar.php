<?php
declare(strict_types=1);
$en = ($portalLanguage ?? 'sv') === 'en';
$t = static fn(string $sv, string $english): string => $en ? $english : $sv;
$user = portal_invoice_auth_personal_user();
?>
<style>
.calendar-wrap{max-width:1000px;margin:auto;display:grid;gap:1rem}.calendar-card{background:var(--surface,#fff);border:1px solid #d4dce1;border-radius:12px;padding:1rem}.calendar-list{display:grid;gap:.7rem}.calendar-event{border:1px solid #d4dce1;border-radius:9px;padding:.8rem}.calendar-form{display:grid;gap:.7rem}.calendar-form label{display:grid;gap:.25rem}.calendar-form input:not([type=checkbox]),.calendar-form select,.calendar-form textarea{width:100%;padding:.55rem}.calendar-event p{white-space:pre-wrap;overflow-wrap:anywhere}.calendar-shares label{display:block}.calendar-meta{color:#536574}

/* Local rules follow the compact base rules so they also apply in the portal. */
@media screen {
.calendar-card { padding:clamp(16px,2.4vw,24px); border-color:var(--color-border); border-radius:16px; box-shadow:0 2px 8px rgba(10,42,40,.05); }
.calendar-card h2 { display:flex; align-items:center; gap:10px; margin:0 0 .5rem; }
.calendar-card h3 { margin:0 0 .35rem; }
.calendar-heading-icon { flex:none; width:21px; height:21px; fill:none; stroke:currentColor; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; color:var(--brand); }
.calendar-event { background:var(--color-surface); border-color:var(--color-border); padding:16px; }
.calendar-event-actions { display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
.calendar-event-actions form { margin:0; }
.calendar-planner { grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px; }
.calendar-planner > .calendar-field-wide { grid-column:1 / -1; }
.calendar-shares { min-width:0; border:1px solid var(--color-border); border-radius:10px; padding:12px; }
.calendar-shares label { padding:5px 0; overflow-wrap:anywhere; }
@media (max-width:720px) { .calendar-card { padding:14px; } .calendar-event-actions > a, .calendar-event-actions > form { flex:1 1 180px; } .calendar-event-actions .btn { width:100%; } }
@media (max-width:380px) { .calendar-planner { grid-template-columns:minmax(0,1fr); } .calendar-planner > .calendar-field-wide { grid-column:auto; } }
}
</style>
<div class="calendar-wrap">
<?php if (!is_array($user) || !portal_calendar_valid_id($user['id'] ?? null)): ?>
<section class="calendar-card"><h2><svg class="calendar-heading-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="3.5" y="5" width="17" height="15.5" rx="2"/><path d="M7.5 3v4M16.5 3v4M3.5 9h17M8 13h2M14 13h2M8 17h2"/></svg><?= h($t('Personlig kalender', 'Personal calendar')) ?></h2>
<p><?= h($t('Logga in med ditt personliga fakturakonto. Central admin och protokollkonton ger inte automatisk åtkomst.', 'Sign in with your personal invoice account. Central admin and service report accounts do not automatically grant access.')) ?></p>
<form class="calendar-form" method="post" action="?tab=calendar">
<input type="hidden" name="action" value="calendar_login"><?= csrf_field() ?>
<label><?= h($t('Användarnamn','Username')) ?><input name="username" maxlength="120" autocomplete="username" required></label>
<label><?= h($t('Sexsiffrig PIN','Six-digit PIN')) ?><input type="password" name="pin" inputmode="numeric" pattern="[0-9]{6}" autocomplete="current-password" required></label>
<button class="btn btn-main" type="submit"><?= h($t('Logga in','Sign in')) ?></button></form></section>
<?php else:
$records = portal_calendar_read();
$users = portal_invoice_auth_read_users();
$recipients = [];
foreach ($users as $candidate) if (($candidate['active'] ?? false) === true && in_array($candidate['role'] ?? '', ['staff','admin'], true) && portal_calendar_valid_id($candidate['id'] ?? null) && $candidate['id'] !== $user['id']) $recipients[$candidate['id']] = (string) ($candidate['name'] ?? $candidate['username'] ?? '');
$events = is_array($records) ? portal_calendar_visible($records, $user['id']) : [];
usort($events, static fn($a,$b) => strcmp((string)($a['date'] ?? '') . (string)($a['start_time'] ?? ''), (string)($b['date'] ?? '') . (string)($b['start_time'] ?? '')));
$editId = is_string($_GET['edit'] ?? null) ? $_GET['edit'] : '';
$edit = null;
foreach ($events as $candidate) if (($candidate['id'] ?? '') === $editId && ($candidate['owner_id'] ?? '') === $user['id']) $edit = $candidate;
$today = date('Y-m-d');
$recent = date('Y-m-d', strtotime('-7 days'));
$overview = array_filter($events, static fn($event) => (($event['status'] ?? '') !== 'completed' && ($event['date'] ?? '') <= $today) || (($event['status'] ?? '') === 'completed' && substr((string)($event['completed_at'] ?? $event['updated_at'] ?? ''), 0, 10) >= $recent));
?>
<section class="calendar-card"><h2><svg class="calendar-heading-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="3.5" y="5" width="17" height="15.5" rx="2"/><path d="M7.5 3v4M16.5 3v4M3.5 9h17M8 13h2M14 13h2M8 17h2"/></svg><?= h($t('Dagsöversikt','Daily overview')) ?></h2><p><?= h($t('Inloggad som','Signed in as')) ?> <strong><?= h($user['name'] ?? $user['username'] ?? '') ?></strong>.</p><form method="post" action="?tab=calendar"><?= csrf_field() ?><input type="hidden" name="action" value="calendar_logout"><button class="btn btn-outline" type="submit"><?= h($t('Logga ut','Sign out')) ?></button></form><p><?= h($t('Planerade och påbörjade arbeten till och med idag samt avslutade de senaste sju dagarna.','Planned and in-progress work up to today, and work completed in the last seven days.')) ?></p>
<?php if ($records === null): ?><p role="alert"><?= h($t('Kalenderlagringen kan inte läsas. Inga ändringar är tillåtna.','Calendar storage cannot be read. Changes are disabled.')) ?></p><?php else: ?>
<?php if (!$overview): ?><p><?= h($t('Inga arbeten att visa i dagsöversikten.','No work to show in the daily overview.')) ?></p><?php endif; ?>
<ul><?php foreach ($overview as $event): ?><li><?= h((string)($event['date'] ?? '')) ?> · <?= h((string)($event['title'] ?? '')) ?> — <a href="#work-<?= h($event['id']) ?>"><?= h($t('Visa nästa steg','View next step')) ?></a></li><?php endforeach; ?></ul>
<?php endif; ?></section>
<?php if ($records !== null): ?>
<section class="calendar-card" id="calendar-edit"><h2><?= h($edit ? $t('Ändra arbete','Edit work') : $t('Planera arbete','Plan work')) ?></h2>
<p><?= h($t('Bara du kan ändra och radera dina arbeten. Valda mottagare kan bara läsa.','Only you can edit and delete your work. Selected recipients can only read it.')) ?></p>
<form class="calendar-form calendar-planner" method="post" action="?tab=calendar">
<?= csrf_field() ?><input type="hidden" name="action" value="calendar_save"><input type="hidden" name="event_id" value="<?= h($edit['id'] ?? '') ?>"><input type="hidden" name="revision" value="<?= h((string)($edit['revision'] ?? '')) ?>">
<label class="calendar-field-wide"><?= h($t('Arbete','Work')) ?><input name="title" maxlength="160" required value="<?= h($edit['title'] ?? '') ?>"></label>
<label><?= h($t('Datum','Date')) ?><input type="date" name="date" required value="<?= h($edit['date'] ?? $today) ?>"></label>
<label><?= h($t('Starttid','Start time')) ?><input type="time" name="start_time" required value="<?= h($edit['start_time'] ?? '08:00') ?>"></label>
<label><?= h($t('Sluttid, valfri','End time, optional')) ?><input type="time" name="end_time" value="<?= h($edit['end_time'] ?? '') ?>"></label>
<label><?= h($t('Status','Status')) ?><select name="status"><?php foreach (['planned'=>$t('Planerat','Planned'),'in_progress'=>$t('Påbörjat','In progress'),'completed'=>$t('Avslutat','Completed')] as $key=>$label): ?><option value="<?= h($key) ?>" <?= ($edit['status'] ?? 'planned') === $key ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></label>
<label class="calendar-field-wide"><?= h($t('Anteckningar och nästa steg','Notes and next step')) ?><textarea name="notes" maxlength="2000" rows="3"><?= h($edit['notes'] ?? '') ?></textarea></label>
<fieldset class="calendar-shares calendar-field-wide"><legend><?= h($t('Dela för läsning med','Share read-only with')) ?></legend><?php if (!$recipients): ?><p><?= h($t('Inga andra aktiva fakturakonton.','No other active invoice accounts.')) ?></p><?php endif; ?>
<?php foreach ($recipients as $id=>$name): ?><label><input type="checkbox" name="shared_with[]" value="<?= h($id) ?>" <?= in_array($id, $edit['shared_with'] ?? [], true) ? 'checked' : '' ?>> <?= h($name) ?></label><?php endforeach; ?></fieldset>
<button class="btn btn-main" type="submit"><?= h($t('Spara arbete','Save work')) ?></button><?php if ($edit): ?><a href="?tab=calendar"><?= h($t('Avbryt ändring','Cancel editing')) ?></a><?php endif; ?></form></section>
<section class="calendar-card"><h2><svg class="calendar-heading-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="3.5" y="5" width="17" height="15.5" rx="2"/><path d="M7.5 3v4M16.5 3v4M3.5 9h17M8 13h2M14 13h2M8 17h2"/></svg><?= h($t('Planerade och delade arbeten','Scheduled and shared work')) ?></h2><div class="calendar-list">
<?php if (!$events): ?><p><?= h($t('Inga arbeten ännu.','No work yet.')) ?></p><?php endif; ?>
<?php foreach ($events as $event): $own = $event['owner_id'] === $user['id']; $statuses = ['planned'=>$t('Planerat','Planned'),'in_progress'=>$t('Påbörjat','In progress'),'completed'=>$t('Avslutat','Completed')]; ?>
<article class="calendar-event" id="work-<?= h($event['id']) ?>"><h3><?= h((string)($event['title'] ?? '')) ?></h3><div class="calendar-meta"><?= h((string)($event['date'] ?? '')) ?> <?= h((string)($event['start_time'] ?? '')) ?><?= !empty($event['end_time']) ? '–'.h((string)$event['end_time']) : '' ?> · <?= h($statuses[$event['status'] ?? ''] ?? '') ?> · <?= h($own ? $t('Ditt arbete','Your work') : $t('Delat med dig · endast läsning','Shared with you · read-only')) ?></div><p><?= h((string)($event['notes'] ?? '')) ?></p>
<?php if ($own): ?><div class="calendar-event-actions"><a class="btn btn-outline" href="?tab=calendar&amp;edit=<?= h($event['id']) ?>#calendar-edit"><?= h($t('Ändra / hantera delning','Edit / manage sharing')) ?></a>
<form method="post" action="?tab=calendar" onsubmit="return confirm(<?= h(json_encode($t('Ta bort arbetet?','Delete this work?'), JSON_HEX_APOS | JSON_HEX_QUOT)) ?>)"><?= csrf_field() ?><input type="hidden" name="action" value="calendar_delete"><input type="hidden" name="event_id" value="<?= h($event['id']) ?>"><input type="hidden" name="revision" value="<?= h((string)($event['revision'] ?? '')) ?>"><button class="btn btn-outline" type="submit"><?= h($t('Ta bort','Delete')) ?></button></form></div><?php endif; ?></article>
<?php endforeach; ?></div></section><?php endif; ?>
<?php endif; ?>
<p><?= h($t('Kalenderdata lagras privat på servern och ingår inte i portalens säkerhetskopia. Serveradministratörer med filåtkomst kan läsa dem.','Calendar data is stored privately on the server and is not included in the portal backup. Server administrators with file access can read it.')) ?></p>
</div>

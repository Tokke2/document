'use strict';
// Isolated CLI request simulations; never touch real portal storage or send mail.
const assert=require('node:assert/strict'), fs=require('node:fs'), path=require('node:path'), {execFileSync}=require('node:child_process');
const root=path.resolve(__dirname,'..'), sandbox=path.join(root,'tests/php/.calendar-pwa-sandbox');
fs.rmSync(sandbox,{recursive:true,force:true}); fs.mkdirSync(sandbox,{recursive:true});
for(const dir of ['includes','tabs','assets']) fs.cpSync(path.join(root,dir),path.join(sandbox,dir),{recursive:true});
for(const f of ['index.php','functions.php','pwa-kalender.php','manifest-kalender.webmanifest','service-worker.js']) fs.copyFileSync(path.join(root,f),path.join(sandbox,f));
for(const d of ['storage','storage/sessions','logs','pdf','svg','uploads']) fs.mkdirSync(path.join(sandbox,d),{recursive:true});
for(const d of ['storage','logs','pdf','svg','uploads']) fs.writeFileSync(path.join(sandbox,d,'.htaccess'),'Require all denied\n');
const q=s=>JSON.stringify(s); const b64=o=>Buffer.from(JSON.stringify(o)).toString('base64');
const call=(code)=>execFileSync('npx',['--yes','@php-wasm/cli','-r',code],{cwd:root,encoding:'utf8',timeout:30000,stdio:['ignore','pipe','pipe']});
let currentId='CalendarSessionTest00000000001';
function request(get={},post=null,id=currentId) {
 const php=`chdir(${q(sandbox)}); $_SERVER['SCRIPT_NAME']='/dokument/pwa-kalender.php'; $_SERVER['REQUEST_METHOD']=${q(post?'POST':'GET')}; $_SERVER['REMOTE_ADDR']='127.0.0.1'; session_save_path(${q(path.join(sandbox,'storage/sessions'))}); session_id(${q(id)}); session_start(); $_GET=json_decode(base64_decode('${b64(get)}'),true); $_POST=json_decode(base64_decode('${b64(post || {})}'),true); require 'pwa-kalender.php';`;
 const out=call(php); assert.ok(!/Fatal error|Warning:|Parse error/.test(out),out); return out;
}
try {
 const manifest=JSON.parse(fs.readFileSync(path.join(root,'manifest-kalender.webmanifest'),'utf8'));
 assert.equal(manifest.id,'./pwa-kalender.php'); assert.equal(manifest.scope,'./pwa-kalender.php'); assert.equal(manifest.start_url,'./pwa-kalender.php');
 const allow=call(`require ${q(path.join(sandbox,'includes/release_updater.php'))}; require ${q(path.join(sandbox,'includes/github_release_updater.php'))}; echo (portal_github_release_allowed_file('pwa-kalender.php') && portal_github_release_allowed_file('manifest-kalender.webmanifest') && !portal_github_release_allowed_file('storage/calendar_events.json')) ? 'ALLOWED' : 'UNSAFE';`); assert.equal(allow,'ALLOWED');
 const setup=call(`chdir(${q(sandbox)}); require 'functions.php'; require 'includes/invoice_auth.php'; $r=portal_invoice_auth_create_first_admin('tester','Tester','123456'); echo ($r['ok'] ?? false) ? 'SETUP_OK' : json_encode($r);`);
 assert.match(setup,/SETUP_OK/);
 let page=request({tab:'admin',download_backup:'secret.zip'});
 assert.match(page,/Personlig kalender/); assert.ok(!page.includes('Central Adminpanel'));
 const match=page.match(/name="csrf_token" value="([a-f0-9]{64})"/); assert.ok(match,'CSRF token rendered'); const csrf=match[1];
 const login=request({}, {action:'calendar_login',csrf_token:csrf,username:'tester',pin:'123456'});
 assert.ok(!login.includes('Inloggningen misslyckades'), 'login failed');
 const sessions=fs.readdirSync(path.join(sandbox,'storage/sessions')).filter(f=>f.startsWith('sess_')); assert.equal(sessions.length,1,`unexpected sessions: ${sessions}`); currentId=sessions[0].slice(5);
 page=request({tab:'invoice'});
 assert.match(page,/Dagsöversikt/); assert.match(page,/Tester/); assert.ok(!page.includes('invoice-form'));
 const csrf2=page.match(/name="csrf_token" value="([a-f0-9]{64})"/)[1];
 const post={action:'calendar_save',csrf_token:csrf2,title:'Synthetic calendar entry',notes:'No private data',date:'2026-11-18',start_time:'08:00',end_time:'09:00',status:'planned',event_id:'',revision:''};
 request({},post); page=request({}); assert.match(page,/Synthetic calendar entry/);
 const before=fs.readFileSync(path.join(sandbox,'storage/calendar_events.json'),'utf8');
 request({}, {...post,csrf_token:'invalid',title:'Must not save'});
 assert.equal(fs.readFileSync(path.join(sandbox,'storage/calendar_events.json'),'utf8'),before,'Invalid CSRF cannot write');
 const settingsPath=path.join(sandbox,'storage/settings.json'); const settingsBefore=fs.existsSync(settingsPath)?fs.readFileSync(settingsPath,'utf8'):null;
 request({tab:'admin'}, {action:'save_company_settings',csrf_token:csrf2,company_name:'Injected'});
 assert.equal(fs.readFileSync(path.join(sandbox,'storage/calendar_events.json'),'utf8'),before);
 assert.equal(fs.existsSync(settingsPath)?fs.readFileSync(settingsPath,'utf8'):null,settingsBefore,'Other portal actions blocked');
 assert.ok(!request({view_file:'storage/calendar_events.json'}).includes('Synthetic calendar entry'),'Private file request rejected');
 assert.ok(!request({download_file:'storage/calendar_events.json'}).includes('Synthetic calendar entry'),'Private download rejected');
 const event=JSON.parse(before)[0]; request({}, {action:'calendar_delete',csrf_token:csrf2,event_id:event.id,revision:String(event.revision)}); assert.equal(JSON.parse(fs.readFileSync(path.join(sandbox,'storage/calendar_events.json'),'utf8')).length,0,'Owner can delete');
 const unauthed=request({tab:'admin'},null,'CalendarSessionTest00000000002');
 assert.match(unauthed,/Personlig kalender/); assert.ok(!unauthed.includes('Synthetic calendar entry'));
 const scoped=call(`define('TADEGA_PWA_APP','calendar'); require ${q(path.join(sandbox,'includes/central_admin_auth.php'))}; echo portal_central_admin_auth_is_scoped_pwa() ? 'SCOPED' : 'UNSAFE';`); assert.equal(scoped,'SCOPED');
 console.log('PASS: isolated calendar PWA routing, personal login, save, CSRF and cross-app isolation checks.');
} finally { fs.rmSync(sandbox,{recursive:true,force:true}); }

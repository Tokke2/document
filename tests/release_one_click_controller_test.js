'use strict';
// Execute the real controller in a disposable copy. The updater is replaced only
// in that copy with a sentinel; no network calls or real installation occur.
const assert=require('node:assert/strict'), fs=require('node:fs'), path=require('node:path'), {execFileSync}=require('node:child_process');
const root=path.resolve(__dirname,'..');
const sandbox=fs.mkdtempSync(path.join(root,'tests/php/.one-click-'));
const q=s=>JSON.stringify(s), b64=o=>Buffer.from(JSON.stringify(o)).toString('base64');
const call=code=>{ const out=execFileSync('npx',['--yes','@php-wasm/cli','-r',code],{cwd:root,encoding:'utf8',timeout:30000,stdio:['ignore','pipe','pipe']}); assert.ok(!/Fatal error|Warning:|Parse error/.test(out),out); return out; };
try {
 for(const dir of ['includes','tabs','assets']) fs.cpSync(path.join(root,dir),path.join(sandbox,dir),{recursive:true});
 for(const f of ['index.php','functions.php','pwa-kalender.php','service-worker.js']) fs.copyFileSync(path.join(root,f),path.join(sandbox,f));
 for(const d of ['storage','storage/sessions','logs','pdf','svg','uploads']) fs.mkdirSync(path.join(sandbox,d),{recursive:true});
 for(const d of ['storage','logs','pdf','svg','uploads']) fs.writeFileSync(path.join(sandbox,d,'.htaccess'),'Require all denied\n');
 const updater=path.join(sandbox,'includes/github_release_updater.php');
 let src=fs.readFileSync(updater,'utf8');
 const marker='function portal_github_release_install_latest(string $root, ?callable $check = null, ?callable $install = null): array\n{';
 assert.ok(src.includes(marker));
 src=src.replace(marker,marker+"\n    file_put_contents($root.'/storage/test-call', 'called'); if (is_file($root.'/storage/test-succeed')) return ['ok'=>true,'code'=>trim(file_get_contents($root.'/storage/test-succeed')),'tag'=>'0.0.1','commit'=>str_repeat('b',40)]; return ['ok'=>false,'code'=>'zip_extension_unavailable'];\n"); fs.writeFileSync(updater,src);
 const setup=call(`chdir(${q(sandbox)}); $_SERVER['SCRIPT_NAME']='/dokument/index.php'; session_save_path(${q(sandbox+'/storage/sessions')}); session_id('ReleaseOneClickSetupSession00000001'); session_start(); require 'functions.php'; require 'includes/invoice_auth.php'; require 'includes/central_admin_auth.php'; $a=portal_invoice_auth_create_first_admin('tester','Tester','123456'); $b=portal_invoice_auth_login('tester','123456'); $c=portal_central_admin_auth_enroll('765432'); if (!($a['ok']??false)||!($b['ok']??false)||!($c['ok']??false)) { echo 'SETUP_ERROR'; } else { echo json_encode(['id'=>session_id(),'admin'=>$_COOKIE[portal_central_admin_auth_session_name()]]); }`);
 const session=JSON.parse(setup); assert.ok(session.id && session.admin);
 // Cookie name comes from the actual auth module, not a hard-coded guess.
 const cookieName=call(`require ${q(sandbox+'/includes/central_admin_auth.php')}; echo portal_central_admin_auth_session_name();`);
 // The isolated requests carry both the primary and central admin sessions
 function run(tab,post=null,admin=true,scoped=false) {
  return call(`chdir(${q(sandbox)}); $_SERVER['SCRIPT_NAME']=${q(scoped?'/dokument/pwa-kalender.php':'/dokument/index.php')}; $_SERVER['REQUEST_METHOD']=${q(post?'POST':'GET')}; session_save_path(${q(sandbox+'/storage/sessions')}); session_id(${q(session.id)}); session_start(); $_COOKIE[${q(cookieName)}]=${q(admin?session.admin:'')}; $_GET=json_decode(base64_decode('${b64({tab})}'),true); $_POST=json_decode(base64_decode('${b64(post||{})}'),true); require ${q(scoped?'pwa-kalender.php':'index.php')};`);
 }
 const page=run('admin'); assert.match(page,/Uppdatera appen nu/);
 const match=page.match(/name="csrf_token" value="([a-f0-9]{64})"/); assert.ok(match); const token=match[1];
 const action={action:'portal_release_install_latest',csrf_token:token}; const wasCalled=()=>fs.existsSync(path.join(sandbox,'storage/test-call'));
 run('admin',{...action,csrf_token:'wrong'}); assert.equal(wasCalled(),false,'Bad CSRF must block update');
 run('admin',action,false); assert.equal(wasCalled(),false,'Personal invoice admin without central admin must not update');
 run('invoice',action); assert.equal(wasCalled(),false,'Other tabs must not update');
 run('admin',action,true,true); assert.equal(wasCalled(),false,'Scoped app must not update');
 const response=run('admin',action); assert.equal(wasCalled(),true,'Central admin POST should call one-click installer');
 assert.match(response,/PHP ZipArchive saknas/,'Installer errors are visible in admin panel');
 const statusFile=path.join(sandbox,'storage/github_release_status.json');
 const markerFile=path.join(sandbox,'storage/github_release_installed.json');
 const now=Math.floor(Date.now()/1000);
 fs.writeFileSync(statusFile,JSON.stringify({ok:true,code:'latest_release_found',tag:'0.0.1',commit:'a'.repeat(40),checked_at:now-300}));
 fs.writeFileSync(markerFile,JSON.stringify({tag:'0.0.1',commit:'b'.repeat(40),installed_at:now-100}));
 const stale=run('admin');
 assert.match(stale,/Den sparade kontrollen är äldre än senaste installation eller matchning/);
 assert.doesNotMatch(stale,/En annan releasecommit rapporterades/);
 assert.match(stale,/aaaaaaaaaaaa/); assert.match(stale,/bbbbbbbbbbbb/);
 fs.writeFileSync(path.join(sandbox,'storage/test-succeed'),'already_current');
 run('admin',action);
 const updated=JSON.parse(fs.readFileSync(statusFile,'utf8'));
 assert.equal(updated.commit,'b'.repeat(40),'A successful manual check updates the old status cache');
 const fresh=run('admin');
 assert.doesNotMatch(fresh,/En annan releasecommit rapporterades|Den sparade kontrollen är äldre/);
 assert.match(fresh,/Senaste releasekontroll/);
 assert.match(fresh,/Datum för faktisk uppdatering saknas/,'Legacy and unchanged matches must not pretend to be an update');
 assert.match(fresh,/Senast installerad eller matchad:.*Europe\/Stockholm/);
 fs.writeFileSync(path.join(sandbox,'storage/test-succeed'),'installed');
 run('admin',action);
 let installed=JSON.parse(fs.readFileSync(markerFile,'utf8'));
 assert.ok(Number.isInteger(installed.last_updated_at) && installed.last_updated_at>=now);
 const afterInstall=run('admin'); assert.match(afterInstall,/Senast uppdaterad:/); assert.match(afterInstall,/Europe\/Stockholm/);
 // A subsequent match must preserve the previous actual file-update time.
 installed.last_updated_at=1700000000; fs.writeFileSync(markerFile,JSON.stringify(installed));
 fs.writeFileSync(path.join(sandbox,'storage/test-succeed'),'already_current');
 run('admin',action);
 assert.equal(JSON.parse(fs.readFileSync(markerFile,'utf8')).last_updated_at,1700000000);
 const afterMatch=run('admin'); assert.match(afterMatch,/Senast uppdaterad:<\/strong> 2023-11-14 23:13/);

 fs.writeFileSync(statusFile,JSON.stringify({ok:true,code:'latest_release_found',tag:'0.0.1',commit:'c'.repeat(40),checked_at:now}));
 fs.writeFileSync(markerFile,JSON.stringify({tag:'0.0.1',commit:'b'.repeat(40),installed_at:now-10}));
 const newer=run('admin'); assert.match(newer,/En annan releasecommit rapporterades vid senaste kontrollen/);

 console.log('PASS: one-click controller CSRF, admin identity, tab/PWA isolation and error response.');
} finally { fs.rmSync(sandbox,{recursive:true,force:true}); }

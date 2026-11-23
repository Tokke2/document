'use strict';
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const {JSDOM} = require('jsdom');
const voice = require('../assets/invoice_voice.js');
const php = 'require "tests/php/invoice_assistance_render_regression.php"; $GLOBALS["TEST_VOICE_PERSONAL"]=true; $GLOBALS["TEST_CATALOG"]=[["id"=>str_repeat("b",32),"name"=>"Vindrutebyte","unit"=>"st","price"=>750.0,"fixed_price"=>true,"aliases"=>["Rutbyte"]],["id"=>str_repeat("c",32),"name"=>"Service","unit"=>"tim","price"=>300.0,"fixed_price"=>false,"aliases"=>[]]]; echo "VOICE_HTML:".base64_encode(render_invoice(false,false,[],"sv"));';
const out = execFileSync('npx',['--yes','@php-wasm/cli','-r',php],{encoding:'utf8'});
assert(out.includes('VOICE_HTML:'),out);
const html=Buffer.from(out.split('VOICE_HTML:')[1],'base64').toString('utf8');
function setup(profile={learning:false,auto:false,reviewed:0,corrections:[],share_learning:false,numeric_learning:false,numeric_corrections:[]}, pageHtml=html) {
  const dom=new JSDOM(pageHtml,{url:'https://example.test/pwa-underlag.php',runScripts:'outside-only',pretendToBeVisual:true});
  const w=dom.window; let engine; const requests=[];
  class Recognition { constructor(){engine=this;} start(){} }
  w.matchMedia=()=>({matches:true,addListener(){},removeListener(){},addEventListener(){}}); w.confirm=()=>true; w.InvoiceVoice=voice; w.SpeechRecognition=Recognition;
  w.fetch=async (url,options)=>{requests.push(new URL(url,w.location.href).pathname);return {ok:true,json:async()=>({ok:true,profile,token:'a'.repeat(32)+'.opaque'})};};
  for(const script of w.document.querySelectorAll('script:not([src])'))w.eval(script.textContent);
  const get=s=>w.document.querySelector(s);
  const say=text=>{get('#voice-start').click(); const result=[{transcript:text}];result.isFinal=true;engine.onresult({results:[result]});engine.onend();};
  return {dom,w,get,say,requests};
}
const flush=()=>new Promise(r=>setImmediate(r));
(async()=>{
 {
  const c=setup(); await flush(); c.get('#invoice-open-voice').click(); assert.equal(c.get('#invoice-voice-panel').open,true);
  assert.equal(c.get('#invoice-open-voice').getAttribute('aria-expanded'),'true');
  c.say('2st Rutbyte'); assert.match(c.get('#voice-match-reason').textContent,/Alternativt artikelnamn/); assert.equal(c.get('#voice-name').value,'Vindrutebyte');assert.equal(c.get('#voice-price').value,'750.00');assert.equal(c.get('#voice-article-choices').hidden,true);
  c.get('#voice-apply').click();assert.equal(c.get('.invoice-item-name').value,'Vindrutebyte');assert.equal(c.get('.invoice-item-price').readOnly,true);
  assert(c.requests.every(p=>p==='/pwa-underlag.php'),'Profile requests remain within the PWA entry point'); c.dom.window.close();
 }
 {
  const c=setup();await flush(); c.say('2st Rutbytte 750 kr st'); assert.equal(c.get('#voice-article-choices').hidden,false);
  assert.match(c.get('#voice-article-options').textContent,/Vindrutebyte.*Liknande namn/); c.get('#voice-apply').click();assert.equal(c.get('.invoice-item-name').value,'');
  c.get('#voice-article-options button').click(); assert.match(c.get('#voice-match-reason').textContent,/Liknande namn/); c.get('#voice-apply').click();assert.equal(c.get('.invoice-item-name').value,'Vindrutebyte');assert.equal(c.get('.invoice-item-price').value,'750.00');c.dom.window.close();
 }
 {
  const c=setup();await flush(); c.say('2st Rutbyte 900 kr st'); assert.match(c.get('#voice-article-message').textContent,/900.*750/);
  assert.equal(c.get('#voice-price').value,'900');c.get('#voice-apply').click();assert.equal(c.get('.invoice-item-name').value,'');
  c.get('#voice-article-options button').click();assert.equal(c.get('#voice-price').value,'750.00'); c.get('#voice-apply').click();assert.equal(c.get('.invoice-item-price').readOnly,true);c.dom.window.close();
 }
 {
  const c=setup();await flush(); c.say('2 tim Rutbyte 750 kr per timme'); assert.equal(c.get('#voice-article-choices').hidden,false);assert.match(c.get('#voice-article-message').textContent,/enhet/); c.get('#voice-apply').click();assert.equal(c.get('.invoice-item-name').value,''); c.dom.window.close();
 }
 {
  const c=setup();await flush(); c.say('2st Rutbyte 900 kr per timme'); assert.equal(c.get('#voice-article-choices').hidden,false,'Unparseable conflicting suffix is not silently ignored');c.get('#voice-apply').click();assert.equal(c.get('.invoice-item-name').value,'');c.dom.window.close();
 }
 {
  const c=setup();await flush();c.say('2st Okänd 900 kr st');assert.equal(c.get('#voice-article-choices').hidden,false); c.get('#voice-apply').click();assert.equal(c.get('.invoice-item-name').value,'');c.get('#voice-article-free').click();assert.match(c.get('#voice-match-reason').textContent,/Fritext vald/);c.get('#voice-apply').click();assert.equal(c.get('.invoice-item-name').value,'Okänd');c.dom.window.close();
 }
 {
  const profile={learning:true,auto:true,reviewed:50,corrections:[{heard:'Lagning',approved:'Vindrutebyte'}],share_learning:false,numeric_learning:false,numeric_corrections:[]};
  const c=setup(profile);await flush();c.say('2st Lagning 750 kr st');await flush();await flush();assert.equal(c.get('.invoice-item-name').value,'');assert.match(c.get('#voice-article-options').textContent,/Vindrutebyte.*Personlig rättning/);c.get('#voice-article-options button').click();assert.match(c.get('#voice-match-reason').textContent,/Personlig rättning/);c.get('#voice-apply').click();assert.equal(c.get('.invoice-item-name').value,'Vindrutebyte');c.dom.window.close();
 }
 {
  const c=setup();await flush();c.say('2st Vindrutebyte');assert.match(c.get('#voice-match-reason').textContent,/Exakt artikelnamn/);
  c.get('#voice-name').value='Annat';c.get('#voice-name').dispatchEvent(new c.w.Event('input',{bubbles:true}));assert.match(c.get('#voice-match-reason').textContent,/ändrats manuellt/);
  c.get('#voice-clear').click();assert.equal(c.get('#voice-match-reason').hidden,true);c.dom.window.close();
 }
 {
  const profile={learning:true,auto:true,reviewed:50,corrections:[{heard:'Lagning',approved:'Vindrutebyte'}],share_learning:false,numeric_learning:false,numeric_corrections:[]};
  const c=setup(profile);await flush();c.say('2st Lagning 750 kr st');await flush();profile.learning=false;
  c.get('#voice-article-options button').click();assert.equal(c.get('#voice-name').value,'Lagning','Paused learning cannot apply a stale personal correction');assert.match(c.get('#voice-status').textContent,/ändrats/);c.dom.window.close();
 }
 {
  const profile={learning:true,auto:true,reviewed:50,corrections:[],share_learning:false,numeric_learning:true,numeric_corrections:[]};
  const c=setup(profile);await flush();c.say('2st Rutbyte');await flush();await flush(); assert.equal(c.get('.invoice-item-name').value,'Vindrutebyte');assert.equal(c.get('.invoice-item-price').value,'750.00');c.dom.window.close();
 }
 {
  const enPhp=php.replace('render_invoice(false,false,[],"sv")','render_invoice(false,false,[],"en")');
  const enOut=execFileSync('npx',['--yes','@php-wasm/cli','-r',enPhp],{encoding:'utf8'});
  const enHtml=Buffer.from(enOut.split('VOICE_HTML:')[1],'base64').toString('utf8');
  const c=setup(undefined,enHtml);await flush();c.say('2st Rutbyte');assert.match(c.get('#voice-match-reason').textContent,/Alternative item name/);c.dom.window.close();
 }
 console.log('PASS: catalog alias, uncertainty, conflicts, learning, mobile and PWA DOM checks.');
})().catch(e=>{console.error(e);process.exitCode=1;});

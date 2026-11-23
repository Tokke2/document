'use strict';
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const {JSDOM, VirtualConsole} = require('jsdom');
const fs = require('node:fs');
const manifest = JSON.parse(fs.readFileSync('manifest-skadeblankett.webmanifest','utf8'));
assert.equal(manifest.start_url,'./skadeblankett-kund.php');
assert.equal(manifest.scope,'./skadeblankett-kund.php');
assert.equal(manifest.display,'standalone');
function render(done=false,internal=false) {
  const php = `require "tests/php/skade_customer_render_fixture.php"; echo base64_encode(render_customer_skade(${done?'true':'false'},${internal?'true':'false'}));`;
  const result = execFileSync('npx',['--yes','@php-wasm/cli','-r',php],{encoding:'utf8'}).trim();
  return Buffer.from(result,'base64').toString('utf8');
}
const errors=[];
const virtual = new VirtualConsole(); virtual.on('jsdomError', e => errors.push(e));
const html=render();
const dom=new JSDOM(`<!doctype html><html><body class="skade-kiosk-mode">${html}</body></html>`,{url:'https://example.test/skadeblankett-kund.php',runScripts:'dangerously',virtualConsole:virtual});
const d=dom.window.document;
// Touch-event regression: this checks cancellation policy, not actual iPad scrolling.
let scale=1;
Object.defineProperty(dom.window,'visualViewport',{configurable:true,value:{get scale(){return scale;}}});
function touch(target,type,points) {
  const event=new dom.window.Event(type,{bubbles:true,cancelable:true});
  Object.defineProperty(event,'touches',{value:points.map(([clientX,clientY])=>({clientX,clientY}))});
  target.dispatchEvent(event); return event.defaultPrevented;
}
const surface=d.querySelector('.skade-card');
function gesture(target,from,to) { touch(target,'touchstart',[from]); const cancelled=touch(target,'touchmove',[to]); touch(target,'touchend',[]); return cancelled; }
assert.equal(gesture(surface,[100,100],[180,105]),true,'Unzoomed horizontal page swipe is blocked');
assert.equal(gesture(surface,[100,100],[105,180]),false,'Vertical scrolling is not blocked');
assert.equal(gesture(surface,[100,100],[102,101]),false,'Tap is not blocked');
assert.equal(gesture(d.querySelector('#skade-signature-pad'),[100,100],[180,105]),false,'Signature canvas is not intercepted');
d.querySelector('#sk-namn').focus(); assert.equal(gesture(d.querySelector('#sk-namn'),[100,100],[180,105]),false,'Editing a focused control is not intercepted');
scale=2; assert.equal(gesture(surface,[100,100],[180,105]),false,'Zoomed page can be panned'); scale=1;
touch(surface,'touchstart',[[100,100]]); touch(surface,'touchstart',[[100,100],[150,150]]); assert.equal(touch(surface,'touchmove',[[130,100],[180,150]]),false,'Pinch zoom is not intercepted'); touch(surface,'touchend',[]);
assert.match(html,/overscroll-behavior-x:none/);
assert.match(html,/textarea:not\(:focus\)\s*\{\s*overflow:hidden;\s*resize:none;/);
assert.match(html,/textarea:focus\s*\{\s*overflow:auto;\s*resize:vertical;/);
const nameField=d.querySelector('#sk-namn'); nameField.value='Oförändrad testtext'; nameField.blur();
assert.equal(gesture(nameField,[100,100],[102,180]),false,'Unfocused textarea must allow vertical page gestures');
assert.equal(nameField.value,'Oförändrad testtext'); assert.notEqual(d.activeElement,nameField,'Scroll gesture must not focus text');
nameField.focus(); assert.equal(gesture(nameField,[100,100],[102,180]),false,'Focused textarea must allow its native editing gestures');

assert.ok(d.querySelector('#skade-install-help').textContent.includes('Lägg till på hemskärmen'));
assert.equal(d.querySelector('#skade-form').getAttribute('action'),'./skadeblankett-kund.php');
assert.ok(d.querySelector('#skade-signature-confirmed').required);
assert.equal(d.querySelector('.skade-submit').hasAttribute('data-mobile-action-dock'),false);
assert.equal(d.querySelector('#skade-progress-track').getAttribute('aria-valuenow'),'0');
const set=(selector,value)=>{let el=d.querySelector(selector);el.value=value;el.dispatchEvent(new dom.window.Event('input',{bubbles:true}));};
set('#sk-namn','Test Testsson\nTestgatan 1'); set('#sk-personnr','19900101-1234'); set('#sk-telefon','0701234567'); set('#sk-regnr','abc 123');
set('#sk-modell','Testbil'); set('#sk-forsakring','Testbolag'); set('#sk-datum','2026-11-03');
assert.equal(d.querySelector('#sk-regnr').value,'ABC123');
let confirm=d.querySelector('#skade-signature-confirmed'); confirm.checked=true;confirm.dispatchEvent(new dom.window.Event('change',{bubbles:true}));
assert.notEqual(d.querySelector('#skade-progress-track').getAttribute('aria-valuenow'),'100','Signature is required');
set('#skade-signature-png','data:image/png;base64,TEST');
assert.equal(d.querySelector('#skade-progress-track').getAttribute('aria-valuenow'),'100');
assert.match(d.querySelector('#skade-progress-text').textContent,/100 %/);
set('#sk-epost','invalid'); assert.equal(d.querySelector('#skade-progress-track').getAttribute('aria-valuenow'),'99','Invalid optional field must not report complete');
set('#sk-epost',''); set('#sk-datum','2026-02-31');assert.notEqual(d.querySelector('#skade-progress-track').getAttribute('aria-valuenow'),'100','Invalid date is incomplete');
set('#sk-datum','2026-11-03'); set('#sk-namn','   ');assert.notEqual(d.querySelector('#skade-progress-track').getAttribute('aria-valuenow'),'100','Whitespace does not satisfy required input');
const event=new dom.window.Event('submit',{bubbles:true,cancelable:true});
assert.equal(d.querySelector('#skade-form').dispatchEvent(event),false,'Invalid form cannot submit');
assert.equal(d.querySelector('#sk-namn').getAttribute('aria-invalid'),'true');
const success=new JSDOM(render(true),{url:'https://example.test/skadeblankett-kund.php?done=1',runScripts:'dangerously',virtualConsole:virtual});
assert.ok(success.window.document.querySelector('#skade-kiosk-notice'));
assert.equal(success.window.document.querySelector('#skade-form'),null);
assert.ok(success.window.document.querySelector('a[href="./skadeblankett-kund.php"]'));
const staffHtml=render(false,true); assert.ok(new JSDOM(staffHtml).window.document.querySelector('#skade-form'));
assert.ok(!staffHtml.includes('textarea:not(:focus) { overflow:hidden; resize:none; }'),'Customer scroll rule must not affect staff mode');
assert.deepEqual(errors,[]);
console.log('PASS: customer PWA install help, progress, validation and success screen DOM checks.');

'use strict';
// Simulated event policy only. Real iPad/Android gestures require device testing.
const assert=require('node:assert/strict'), {JSDOM,VirtualConsole}=require('jsdom'), {execFileSync}=require('node:child_process');
const php='require "tests/php/skade_customer_render_fixture.php"; echo base64_encode(render_customer_skade());';
const html=Buffer.from(execFileSync('npx',['--yes','@php-wasm/cli','-r',php],{encoding:'utf8'}).trim(),'base64').toString('utf8');
assert.match(html,/skade-kiosk-standalone:not\(\.skade-kiosk-zoomed\)\s*\{\s*touch-action:pan-y/);
function make(mode) {
 const errors=[], vc=new VirtualConsole(); vc.on('jsdomError',e=>errors.push(e));
 let zoom=1,resize=[];
 const dom=new JSDOM(`<!doctype html><html class="skade-kiosk-root"><body class="skade-kiosk-mode">${html}</body></html>`,{url:'https://example.test/skadeblankett-kund.php',runScripts:'dangerously',virtualConsole:vc,beforeParse(w){
  Object.defineProperty(w.navigator,'standalone',{value:mode==='ios',configurable:true});
  w.matchMedia=()=>({matches:mode==='android',addEventListener(){}});
  w.visualViewport={get scale(){return zoom},height:900,offsetTop:0,addEventListener(type,cb){if(type==='resize') resize.push(cb);}};
 }});
 return {dom,errors,setZoom(v){zoom=v; resize.forEach(fn=>fn());}};
}
function send(w,target,type,points) {
 const e=new w.Event(type,{bubbles:true,cancelable:true}); Object.defineProperty(e,'touches',{value:points.map(p=>({clientX:p[0],clientY:p[1],target:p[2]||target}))}); target.dispatchEvent(e); return e.defaultPrevented;
}
function swipe(w,el,x,y) { send(w,el,'touchstart',[[100,100]]); let r=send(w,el,'touchmove',[[100+x,100+y]]); send(w,el,'touchend',[]); return r; }
for (const mode of ['ios','android','browser']) {
 const {dom,errors,setZoom}=make(mode), w=dom.window, d=w.document;
 const root=d.documentElement, surface=d.querySelector('.skade-card'), canvas=d.querySelector('#skade-signature-pad'), field=d.querySelector('#sk-namn');
 assert.equal(root.classList.contains('skade-kiosk-standalone'),mode!=='browser');
 assert.equal(swipe(w,surface,80,4),true,'Rightward movement blocked at normal scale');
 assert.equal(swipe(w,surface,-80,4),true,'Leftward movement blocked at normal scale');
 assert.equal(swipe(w,surface,4,80),false,'Vertical page scroll must remain native');
 assert.equal(swipe(w,surface,1,1),false,'A normal tap must not be cancelled');
 field.value='Texten ska finnas kvar'; field.focus();
 assert.equal(swipe(w,field,70,1),mode!=='browser','Standalone limits sideways drag even over a focused field');
 assert.equal(swipe(w,field,1,70),false,'Vertical movement inside an active field remains native');
 assert.equal(field.value,'Texten ska finnas kvar'); assert.equal(d.activeElement,field);
 assert.equal(swipe(w,canvas,70,1),false,'Drawing across the signature canvas is never intercepted');
 send(w,surface,'touchstart',[[20,20]]);
 const multi=send(w,surface,'touchstart',[[20,20],[80,80]]);
 assert.equal(multi,mode!=='browser','Only installed app should block an ordinary pinch');
 assert.equal(send(w,surface,'touchmove',[[20,20],[110,110]]),mode!=='browser'); send(w,surface,'touchend',[]);
 let gesture=new w.Event('gesturestart',{bubbles:true,cancelable:true}); surface.dispatchEvent(gesture); assert.equal(gesture.defaultPrevented,mode!=='browser');
 send(w,canvas,'touchstart',[[20,20]]);
 assert.equal(send(w,surface,'touchstart',[[20,20,canvas],[60,60,surface]]),false,'A second finger/palm does not cancel an active signature');
 assert.equal(send(w,surface,'touchmove',[[30,20,canvas],[60,60,surface]]),false); send(w,surface,'touchend',[]);
 send(w,surface,'touchstart',[[20,20,surface]]);
 assert.equal(send(w,canvas,'touchstart',[[20,20,surface],[60,60,canvas]]),false,'A stylus entering after a palm is not cancelled'); send(w,canvas,'touchend',[]);
 setZoom(2); assert.equal(root.classList.contains('skade-kiosk-zoomed'),true); assert.equal(swipe(w,surface,70,0),false,'Already zoomed users can pan');
 gesture=new w.Event('gesturestart',{bubbles:true,cancelable:true}); surface.dispatchEvent(gesture); assert.equal(gesture.defaultPrevented,false,'Already zoomed users can zoom back out');
 setZoom(1); assert.equal(root.classList.contains('skade-kiosk-zoomed'),false);
 assert.deepEqual(errors,[]);
}
console.log('PASS: installed customer-app touch policy, signature exemptions, vertical scroll and accessibility zoom.');

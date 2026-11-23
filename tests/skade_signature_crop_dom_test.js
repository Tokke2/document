'use strict';
// Drive the actual customer export function with simulated canvas pixels.
// This does not replace visual testing of the PNG/PDF on an iPad.
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const {JSDOM, VirtualConsole} = require('jsdom');
const php = 'require "tests/php/skade_customer_render_fixture.php"; echo base64_encode(render_customer_skade());';
const html = Buffer.from(execFileSync('npx',['--yes','@php-wasm/cli','-r',php],{encoding:'utf8'}).trim(),'base64').toString('utf8');
const errors=[], vc=new VirtualConsole(); vc.on('jsdomError',e=>errors.push(e));
let options, width=160, height=80, ink=[], draw, output;
const dom = new JSDOM(`<!doctype html><html><body class="skade-kiosk-mode">${html}</body></html>`, {
 url:'https://example.test/skadeblankett-kund.php', runScripts:'dangerously', virtualConsole:vc,
 beforeParse(w) {
  const context={getImageData(){
   const data=new Uint8ClampedArray(width*height*4);
   for(const [x,y,alpha=255] of ink) {const pos=(y*width+x)*4;data[pos]=7;data[pos+1]=81;data[pos+2]=166;data[pos+3]=alpha;}
   return {data};
  }};
  w.HTMLCanvasElement.prototype.getContext=function(){
   if(this.id==='skade-signature-pad') return context;
   output=this; return {drawImage(...args){draw=args;},imageSmoothingEnabled:false};
  };
  w.HTMLCanvasElement.prototype.toDataURL=()=> 'data:image/png;base64,TEST';
  w.TadegaSignaturePad={attach(canvas, opts){
   options=opts; canvas.width=width;canvas.height=height;
   canvas.getBoundingClientRect=()=>({width:width/2,height:height/2,top:0,left:0,right:width/2,bottom:height/2});
   return {context,clear(){ink=[];},resize(){}};
  }};
 }
});
const d=dom.window.document, canvas=d.querySelector('#skade-signature-pad'), input=d.querySelector('#skade-signature-png'), status=d.querySelector('#skade-signature-status');
function exportInk(points){ink=points;options.onEnd();}
exportInk([[1,1,1],[100,60]]);
assert.equal(input.value,'data:image/png;base64,TEST');
assert.equal(output.width,126); assert.equal(output.height,86);
assert.deepEqual(draw.slice(1),[1,1,100,60,13,13,100,60], 'Near-edge strokes get a full transparent margin');
for(const edge of [[[2,0,1]], [[2,79]], [[0,2]], [[159,2]]]) {
 exportInk(edge); assert.equal(input.value,'', 'Clipped ink must not be submitted');
 assert.match(status.textContent,/nå[d]?de kanten/i); assert.equal(status.getAttribute('data-state'),'error');
}
// A clipped drawing remains on the pad and validation must not silently accept it.
assert.deepEqual(ink,[[159,2]]);
const form=d.querySelector('#skade-form');
for(const [id,value] of Object.entries({'sk-namn':'Testperson\nTestgatan','sk-personnr':'19900101-1234','sk-telefon':'0701234567','sk-regnr':'ABC123','sk-modell':'Bil','sk-forsakring':'Bolag','sk-datum':'2026-11-03'})) {
 const field=d.getElementById(id);field.value=value;field.dispatchEvent(new dom.window.Event('input',{bubbles:true}));
}
d.querySelector('#skade-review-open').click();d.querySelector('#skade-review-continue').click();
const submit=new dom.window.Event('submit',{bubbles:true,cancelable:true});
assert.equal(form.dispatchEvent(submit),false); assert.match(status.textContent,/kanten/); assert.equal(d.activeElement,canvas);
d.querySelector('#skade-signature-clear').click(); assert.deepEqual(ink,[]);
exportInk([[30,30],[90,50]]); assert.equal(input.value,'data:image/png;base64,TEST');assert.equal(status.getAttribute('data-state'),null);
// Large HiDPI canvases must still meet every server-side image limit.
width=2400;height=1800;canvas.width=width;canvas.height=height;
exportInk([[1,1],[2398,1798]]);
assert.equal(input.value,'data:image/png;base64,TEST');
assert.ok(output.width<=1600 && output.height<=800 && output.width*output.height<=1200000);
assert.ok(draw[5]>0 && draw[6]>0 && draw[5]+draw[7]<output.width && draw[6]+draw[8]<output.height,'Padding remains after downscaling');
assert.deepEqual(errors,[]);
console.log('PASS: complete signature crop, safe PDF padding, edge warning, redraw and high-DPI limits.');

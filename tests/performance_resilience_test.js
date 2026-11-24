'use strict';
// Deterministic resource-lifecycle checks, not a device energy benchmark.
const assert=require('node:assert/strict'), fs=require('node:fs'), {JSDOM}=require('jsdom');
const source=fs.readFileSync('tabs/protocol.php','utf8');
const start=source.indexOf(' function optimizeMeterPhoto(file){'), end=source.indexOf(' function validateMeterPhoto(){',start);
assert.ok(start>0 && end>start);
const factory=new Function('window','document','File',source.slice(start,end)+';return optimizeMeterPhoto;');
async function photoScenario({width=5000,height=3000,size=5000000,mode='ok'}={}) {
 let closed=0,canvas=null,drawn=0,encoding=0;
 const bitmap={width,height,close(){closed++;}};
 const window={createImageBitmap(){return mode==='decode-error'?Promise.reject(Error('decode')):Promise.resolve(bitmap);}};
 const document={createElement(){
  canvas={width:0,height:0,getContext(){if(mode==='context-error')throw Error('context');return {fillRect(){},drawImage(){drawn++;}};},
   toBlob(cb){encoding++;if(mode==='blob-error')throw Error('encode');cb(mode==='empty'?null:{size:1000000});}};
  return canvas;
 }};
 function File(_parts,name,opts){this.name=name;this.type=opts.type;}
 const file={size,type:'image/jpeg'};
 const result=await factory(window,document,File)(file);
 return {closed,canvas,drawn,encoding,result,file};
}
(async()=>{
 let r=await photoScenario(); assert.equal(r.closed,1);assert.equal(r.drawn,1);assert.equal(r.encoding,1);assert.equal(r.canvas.width,0);assert.equal(r.canvas.height,0);assert.equal(r.result.type,'image/jpeg');
 r=await photoScenario({width:1000,height:600,size:100000});assert.equal(r.closed,1);assert.equal(r.canvas,null);assert.equal(r.result,r.file,'Small photos are not needlessly recompressed');
 for(const mode of ['context-error','blob-error','empty']) {r=await photoScenario({mode});assert.equal(r.closed,1,mode);assert.equal(r.canvas.width,0,mode);assert.equal(r.canvas.height,0,mode);assert.equal(r.result,r.file,mode);}
 r=await photoScenario({mode:'decode-error'});assert.equal(r.closed,0);assert.equal(r.result,r.file);
 assert.match(source,/if\(!event\.persisted\)\{\s*photoSelectionVersion\+\+;\s*if\(photoPreviewUrl\)\{URL\.revokeObjectURL\(photoPreviewUrl\)/,'Do not invalidate a page restored from bfcache; cancel pending work on final unload');

 const index=fs.readFileSync('index.php','utf8'), pos=index.indexOf("var docks = Array.prototype.slice.call(document.querySelectorAll('[data-mobile-action-dock]'))");
 const first=index.lastIndexOf('(function () {',pos), last=index.indexOf('})();',pos)+5;
 assert.ok(pos>0&&first>=0&&last>first);
 const dom=new JSDOM('<!doctype html><html><body><button data-mobile-action-dock>Save</button></body></html>',{runScripts:'outside-only',pretendToBeVisual:true});
 const w=dom.window, listeners={}, timers=new Map(), frames=[]; let next=1;
 w.matchMedia=()=>({matches:true,addEventListener(){}});
 w.visualViewport={height:300,offsetTop:0,addEventListener(name,fn){listeners[name]=fn;}};
 Object.defineProperty(w,'innerHeight',{value:800,configurable:true});
 w.setTimeout=(fn,ms)=>{const id=next++;timers.set(id,{fn,ms});return id;}; w.clearTimeout=id=>timers.delete(id);
 w.requestAnimationFrame=fn=>{frames.push(fn);return next++;};
 w.eval(index.slice(first,last));
 assert.equal([...timers.values()].filter(t=>t.ms===80).length,1);
 for(let i=0;i<100;i++){listeners.scroll();assert.equal(frames.length,1);frames.shift()();}
 assert.equal([...timers.values()].filter(t=>t.ms===80).length,1,'Viewport bursts coalesce into a single pending visibility timer');
 w.visualViewport.height=800; listeners.resize();frames.shift()();
 assert.equal([...timers.values()].filter(t=>t.ms===80).length,0,'Closing keyboard cancels obsolete work');
 dom.window.close();
 console.log('PASS: photo resource cleanup, safe fallback, bfcache handling and coalesced viewport timers.');
})().catch(e=>{console.error(e);process.exitCode=1;});

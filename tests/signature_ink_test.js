'use strict';
// Exercise the actual pen renderer using synthetic pointer events and a recording canvas.
// This cannot replace checking the drawn PNG/PDF on a real iPad.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('assets/signature_pad.js', 'utf8');
function draw(options, pointerType, timeScale = 1) {
    const marks = [], listeners = {};
    const ctx = {
        save(){}, restore(){}, setTransform(){}, clearRect(){}, fillRect(){},
        beginPath(){}, moveTo(){}, lineTo(){}, arc(){},
        fill(){ marks.push({color:this.fillStyle, width:0}); },
        stroke(){ marks.push({color:this.strokeStyle, width:this.lineWidth}); },
    };
    const canvas = {
        width:800, height:200, style:{},
        getContext(){return ctx;}, getBoundingClientRect(){return {left:0,top:0,width:400,height:100};},
        addEventListener(type, fn){listeners[type]=fn;}, setPointerCapture(){}, hasPointerCapture(){return false;},
    };
    const window = {requestAnimationFrame(fn){fn();}, addEventListener(){}};
    vm.runInNewContext(source,{window});
    const pad = window.TadegaSignaturePad.attach(canvas, options);
    const event = (type, x, y, time, pressure) => ({type,clientX:x,clientY:y,timeStamp:10+(time-10)*timeScale,pressure,
        pointerType,pointerId:1,isPrimary:true,button:0,cancelable:true,preventDefault(){}});
    listeners.pointerdown(event('pointerdown',20,40,10,0.45));
    listeners.pointermove(event('pointermove',60,44,35,0.5));
    listeners.pointermove(event('pointermove',100,38,60,0.55));
    listeners.pointerup(event('pointerup',140,44,85,0.5));
    assert.equal(pad.hasInk,true);
    const width = Math.max(...marks.map(mark=>mark.width));
    assert.ok(width>0); assert.ok(marks.every(mark=>mark.color===options.color));
    pad.clear(); assert.equal(pad.hasInk,false);
    return width;
}
const damageForm = {color:'#1a3f96', baseWidthCssPx:2.6, speedThinBase:1.18, speedThinRange:0.42};
for (const pointerType of ['touch','pen']) {
    const oldWidth=draw({color:'#18385f'},pointerType);
    const newWidth=draw(damageForm,pointerType);
    assert.ok(newWidth > oldWidth * 1.25 && newWidth < oldWidth * 1.5, `${pointerType}: damage-form ink should be moderately thicker (old=${oldWidth}, new=${newWidth})`);
}
// Finger strokes must vary more with speed than the shared classic defaults,
// so a drawn signature looks like real pen ink instead of a uniform marker.
// Pen input already varies with contact pressure and is not speed-driven.
for (const pointerType of ['touch']) {
    const classic = {
        slow: draw({color:'#18385f'}, pointerType, 4),
        fast: draw({color:'#18385f'}, pointerType, 0.5),
    };
    const natural = {
        slow: draw(damageForm, pointerType, 4),
        fast: draw(damageForm, pointerType, 0.5),
    };
    const classicSpread = classic.slow - classic.fast;
    const naturalSpread = natural.slow - natural.fast;
    assert.ok(naturalSpread > classicSpread * 1.6,
        `${pointerType}: natural dynamics should widen speed-based variation (classic=${classicSpread}, natural=${naturalSpread})`);
}
const form = fs.readFileSync('tabs/skadeblankett.php','utf8');
assert.match(form,/color:\s*'#1a3f96',\s*baseWidthCssPx:\s*2\.6,\s*speedThinBase:\s*1\.18,\s*speedThinRange:\s*0\.42/);
console.log('PASS: damage-form ink is deep blue, moderately thicker and varies more with stroke speed; shared defaults remain unchanged.');

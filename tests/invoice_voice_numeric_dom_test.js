'use strict';
// Simulated browser: numeric corrections are advisory and always require review.
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { JSDOM } = require('jsdom');
const voice = require('../assets/invoice_voice.js');
const output = execFileSync('npx', ['--yes','@php-wasm/cli','-r','require "tests/php/invoice_assistance_render_regression.php"; $GLOBALS["TEST_VOICE_PERSONAL"]=true; echo "VOICE_HTML:".base64_encode(render_invoice(true,false,[],"sv"));'], {encoding:'utf8'});
const html = Buffer.from(output.split('VOICE_HTML:')[1], 'base64').toString('utf8');
const flush = () => new Promise(resolve => setImmediate(resolve));
(async () => {
    const dom = new JSDOM(html, {url:'https://example.test/?tab=invoice', runScripts:'outside-only', pretendToBeVisual:true});
    const w = dom.window, profile = {learning:true, auto:true, reviewed:50, corrections:[], share_learning:false,numeric_learning:false, numeric_corrections:[]};
    const tokens = new Map(); let counter = 0, engine, requests = [], confirms = 0;
    function tokenFor(data) { const token = 'a'.repeat(32) + '.T' + (++counter); tokens.set(token, data); return token; }
    class Recognition { constructor() { engine=this; } start() {} }
    w.matchMedia = () => ({matches:false, addListener(){}, removeListener(){}, addEventListener(){}});
    w.confirm = () => { confirms++; return true; }; w.SpeechRecognition=Recognition; w.InvoiceVoice=voice;
    w.fetch = async (_url, opts) => {
        const p = opts && opts.body;
        if (!p || p.get('action') !== 'voice_learning') return {ok:true,json:async()=>({ok:true})};
        const op=p.get('operation'); requests.push({op, keys:Array.from(p.keys())});
        if (op==='numeric_on') profile.numeric_learning=true;
        if (op==='numeric_off') profile.numeric_learning=false;
        let token;
        if (op==='track_auto') token=tokenFor({heard:p.get('heard'), baseline:p.get('approved'), unit:p.get('numeric_unit'), qty:p.get('numeric_qty'), price:p.get('numeric_price'), baseQty:p.get('numeric_qty'), basePrice:p.get('numeric_price')});
        if (op==='sync_auto') {
            const previous=tokens.get(p.get('voice_token')); if (!previous) return {ok:false,json:async()=>({code:'invalid_token'})};
            const state={...previous};
            for (const field of ['qty','price']) {
                const value=p.get('numeric_'+field), base=field==='qty'?'baseQty':'basePrice';
                if (profile.numeric_learning && value && state.unit===p.get('numeric_unit') && value!==state[base]) {
                    profile.numeric_corrections=profile.numeric_corrections.filter(x=>!(x.heard===state.heard && x.unit===state.unit && x.field===field && x.from===state[field]));
                    if(value!==state[field]) profile.numeric_corrections.push({heard:state.heard,unit:state.unit,field,from:state[field],to:value});
                    state[base]=value;
                }
            }
            token=tokenFor(state);
        }
        return {ok:true,json:async()=>({ok:true,profile:structuredClone(profile),token})};
    };
    for (const script of w.document.querySelectorAll('script:not([src])')) w.eval(script.textContent);
    const get=s=>w.document.querySelector(s);
    async function say(text) { get('#voice-start').click(); const result=[{transcript:text}]; result.isFinal=true; engine.onresult({results:[result]}); engine.onend(); await flush(); await flush(); }
    await flush();
    assert.equal(profile.numeric_learning,false,'Numbers require separate opt-in');
    get('#voice-numeric-toggle').click(); await flush();
    assert.equal(profile.numeric_learning,true); assert(confirms > 0,'User is informed before storing price examples');
    await say('2st Glasreparationer 750 kr st');
    let row=get('.invoice-item-name').closest('tr');
    assert.equal(row.querySelector('.invoice-item-name').value,'Glasreparationer','No matching correction allows normal automatic entry');
    row.querySelector('.invoice-item-qty').value='3'; row.querySelector('.invoice-item-qty').dispatchEvent(new w.Event('change',{bubbles:true}));
    await flush(); await flush();
    row.querySelector('.invoice-item-price').value='800'; row.querySelector('.invoice-item-price').dispatchEvent(new w.Event('change',{bubbles:true}));
    await flush(); await flush();
    assert.deepEqual(profile.numeric_corrections.map(x=>[x.field,x.from,x.to]),[['qty','2','3'],['price','750','800']]);
    assert.equal(profile.reviewed,50,'Automatic rows and subsequent edits are not manual-review examples');
    const savedToken=row.querySelector('.invoice-voice-token').value;
    const reopened=new JSDOM(html,{url:'https://example.test/?tab=invoice&edit_id=example',runScripts:'outside-only',pretendToBeVisual:true});
    const r=reopened.window; r.matchMedia=w.matchMedia; r.confirm=()=>true; r.fetch=w.fetch; r.InvoiceVoice=voice; r.SpeechRecognition=Recognition;
    r.document.querySelector('.invoice-item-name').value='Glasreparationer';
    r.document.querySelector('.invoice-item-qty').value='3'; r.document.querySelector('.invoice-item-unit').value='st';
    r.document.querySelector('.invoice-item-price').value='800'; r.document.querySelector('.invoice-voice-token').value=savedToken;
    for(const script of r.document.querySelectorAll('script:not([src])')) r.eval(script.textContent);
    await flush(); await flush();
    r.document.querySelector('.invoice-item-price').value='825';
    r.document.querySelector('.invoice-item-price').dispatchEvent(new r.Event('change',{bubbles:true}));
    await flush(); await flush();
    assert(profile.numeric_corrections.some(x=>x.field==='price' && x.to==='825'), 'A later numeric correction to a reopened automatic row is learned');
    reopened.window.close();
    const count=w.document.querySelectorAll('.invoice-item-name').length;
    await say('2st Glasreparationer 750 kr st');
    assert.equal(w.document.querySelectorAll('.invoice-item-name').length,count,'A matching numeric correction blocks automatic entry');
    assert.equal(get('#voice-qty').value,'2'); assert.equal(get('#voice-price').value,'750','Past numbers are never silently substituted');
    assert.equal(get('#voice-numeric-suggestions').hidden,false);
    get('#voice-numeric-qty').click(); get('#voice-numeric-price').click();
    assert.equal(get('#voice-qty').value,'3'); assert.equal(get('#voice-price').value,'825');
    assert.equal(w.document.querySelectorAll('.invoice-item-name').length,count,'Even accepting numeric hints only edits the preview');
    get('#voice-apply').click(); await flush();
    const added=w.document.querySelectorAll('.invoice-item-name');
    assert.equal(added.length,count+1); assert.equal(w.document.querySelectorAll('.invoice-item-price')[count].value,'825','User can explicitly apply the reviewed line');
    get('#voice-clear').click();
    await say('2st Annan artikel 750 kr st');
    assert.equal(w.document.querySelectorAll('.invoice-item-name').length,count+2,'Corrections do not match a different description');
    assert.equal(get('#voice-numeric-suggestions').hidden,true);
    get('#voice-clear').click();
    await say('2 timmar Glasreparationer 750 kronor per timme');
    assert.equal(w.document.querySelectorAll('.invoice-item-name').length,count+3,'Corrections do not match a different unit');
    assert.equal(get('#voice-numeric-suggestions').hidden,true);
    assert(requests.some(r=>r.op==='track_auto' && r.keys.includes('numeric_qty') && r.keys.includes('numeric_price')));
    dom.window.close();
    console.log('PASS: advisory numeric learning and manual-review simulated DOM checks.');
})().catch(err=>{console.error(err);process.exitCode=1;});

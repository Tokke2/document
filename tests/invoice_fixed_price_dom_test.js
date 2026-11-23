'use strict';
const assert=require('node:assert/strict');
const {execFileSync}=require('node:child_process');
const {JSDOM}=require('jsdom');
const voice=require('../assets/invoice_voice.js');
const script='require "tests/php/invoice_assistance_render_regression.php"; $GLOBALS["TEST_CATALOG"]=[["id"=>str_repeat("b",32),"name"=>"Glasbyte","unit"=>"st","price"=>750.0,"fixed_price"=>true]]; echo "VOICE_HTML:".base64_encode(render_invoice(false,false,[],"sv"));';
const output=execFileSync('npx',['--yes','@php-wasm/cli','-r',script],{encoding:'utf8'});
const html=Buffer.from(output.split('VOICE_HTML:')[1],'base64').toString('utf8');
function setup(){
    const dom=new JSDOM(html,{url:'https://example.test/?tab=invoice',runScripts:'outside-only',pretendToBeVisual:true});
    const w=dom.window; let engine;
    class Recognition{ constructor(){engine=this;} start(){} }
    w.matchMedia=()=>({matches:false,addListener(){},removeListener(){},addEventListener(){}});w.confirm=()=>true;w.InvoiceVoice=voice;w.SpeechRecognition=Recognition;
    w.fetch=async()=>({ok:true,json:async()=>({ok:true})});
    for(const script of w.document.querySelectorAll('script:not([src])')) w.eval(script.textContent);
    return {dom,w,get:s=>w.document.querySelector(s),engine:()=>engine};
}
{
    const c=setup(); const select=c.get('#invoice-catalog-article'); select.value='b'.repeat(32); c.get('#invoice-add-catalog-article').click();
    assert.equal(c.get('.invoice-item-name').value,'Glasbyte'); assert.equal(c.get('.invoice-item-price').value,'750.00');
    assert.equal(c.get('.invoice-item-price').readOnly,true,'Catalog fixed price is read-only');
    assert.equal(c.get('.invoice-item-name').readOnly,true,'Name cannot silently unlink a fixed item');
    assert.equal(c.get('.invoice-item-unit').disabled,true,'Unit cannot silently unlink a fixed item');
    assert.equal(c.get('.invoice-item-qty').readOnly,false,'Quantity remains editable');
    const formData=new c.w.FormData(c.get('#invoice-form'));
    assert.equal(formData.get('items[0][unit]'),'st','Locked unit remains present in form submissions');
    c.get('[data-remove-line]').click();
    assert.equal(c.get('.invoice-item-price').readOnly,false,'Removing the item clears the lock');
    c.dom.window.close();
}
{
    const c=setup(); c.get('#voice-start').click(); const result=[{transcript:'2st Glasbyte 900 kr st'}];result.isFinal=true;
    c.engine().onresult({results:[result]});c.engine().onend();c.get('#voice-apply').click();
    assert.equal(c.get('.invoice-item-name').value,'','A mismatched dictated price cannot populate a fixed-price article');
    assert.match(c.get('#voice-article-message').textContent,/fast pris/);
    c.get('#voice-article-options button').click(); c.get('#voice-apply').click();
    assert.equal(c.get('.invoice-item-name').value,'Glasbyte');assert.equal(c.get('.invoice-item-price').readOnly,true,'A matching voice line is locked too');
    c.dom.window.close();
}
{
    const c=setup(); const name=c.get('.invoice-item-name'); name.value='Glasbyte'; name.dispatchEvent(new c.w.Event('change',{bubbles:true}));
    assert.equal(c.get('.invoice-item-price').value,'750.00','Typing an exact catalog item fills its fixed price when empty');
    assert.equal(c.get('.invoice-item-price').readOnly,true); c.dom.window.close();
}
{
    const savedScript='require "tests/php/invoice_assistance_render_regression.php"; $GLOBALS["TEST_INVOICES"]=[["id"=>"invoice123","items"=>[["art"=>"Glasbyte","unit"=>"st","qty"=>"1","price"=>"600","fixed_lock"=>str_repeat("c",32)]]]]; $GLOBALS["TEST_CATALOG"]=[]; echo "VOICE_HTML:".base64_encode(render_invoice(false,false,["edit_id"=>"invoice123"],"sv"));';
    const savedOutput=execFileSync('npx',['--yes','@php-wasm/cli','-r',savedScript],{encoding:'utf8'});
    const savedDom=new JSDOM(Buffer.from(savedOutput.split('VOICE_HTML:')[1],'base64').toString('utf8'),{url:'https://example.test/?tab=invoice&edit_id=invoice123',runScripts:'outside-only',pretendToBeVisual:true});
    const w=savedDom.window; w.matchMedia=()=>({matches:false,addListener(){},removeListener(){},addEventListener(){}});w.fetch=async()=>({ok:true,json:async()=>({ok:true})});w.InvoiceVoice=voice;
    for(const script of w.document.querySelectorAll('script:not([src])'))w.eval(script.textContent);
    assert.equal(w.document.querySelector('.invoice-item-price').value,'600','Saved historical prices are not replaced by catalog changes');
    assert.equal(w.document.querySelector('.invoice-item-price').readOnly,true,'A saved fixed row remains locked even after the catalog item is removed');
    savedDom.window.close();
}
(async () => {
    const personalScript='require "tests/php/invoice_assistance_render_regression.php"; $GLOBALS["TEST_VOICE_PERSONAL"]=true; $GLOBALS["TEST_CATALOG"]=[["id"=>str_repeat("b",32),"name"=>"Glasbyte","unit"=>"st","price"=>750.0,"fixed_price"=>true]]; echo "VOICE_HTML:".base64_encode(render_invoice(false,false,[],"sv"));';
    const encoded=execFileSync('npx',['--yes','@php-wasm/cli','-r',personalScript],{encoding:'utf8'});
    const dom=new JSDOM(Buffer.from(encoded.split('VOICE_HTML:')[1],'base64').toString('utf8'),{url:'https://example.test/?tab=invoice',runScripts:'outside-only',pretendToBeVisual:true});
    const w=dom.window;let engine; class Recognition{constructor(){engine=this;}start(){}}
    w.matchMedia=()=>({matches:false,addListener(){},removeListener(){},addEventListener(){}});w.confirm=()=>true;w.InvoiceVoice=voice;w.SpeechRecognition=Recognition;
    const profile={learning:true,auto:true,reviewed:50,corrections:[],share_learning:false,numeric_learning:true,numeric_corrections:[{heard:'Glasbyte',unit:'st',field:'price',from:'900',to:'500'}]};
    w.fetch=async (_url,options)=>options && options.body && options.body.get('action')==='voice_learning' ? {ok:true,json:async()=>({ok:true,profile,token:'a'.repeat(32)+'.opaque'})} : {ok:true,json:async()=>({ok:true})};
    for(const script of w.document.querySelectorAll('script:not([src])')) w.eval(script.textContent);
    const flush=()=>new Promise(resolve=>setImmediate(resolve)), get=s=>w.document.querySelector(s);
    await flush();get('#voice-start').click();const result=[{transcript:'2st Glasbyte 900 kr st'}];result.isFinal=true;engine.onresult({results:[result]});engine.onend();await flush();await flush();
    assert.equal(get('.invoice-item-name').value,'','Automatic mode must not insert a conflicting fixed price');
    assert.equal(get('#voice-numeric-suggestions').hidden,true,'Past learned prices never suggest replacing a fixed price');
    assert.match(get('#voice-article-message').textContent,/fast pris/);
    dom.window.close();
    console.log('PASS: fixed-price catalog and voice simulated DOM checks.');
})().catch(error=>{console.error(error);process.exitCode=1;});

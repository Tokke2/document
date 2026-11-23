'use strict';
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { JSDOM } = require('jsdom');
const voice = require('../assets/invoice_voice.js');
const php = 'require "tests/php/invoice_assistance_render_regression.php"; $GLOBALS["TEST_VOICE_PERSONAL"]=true; $GLOBALS["TEST_CATALOG"]=[["id"=>str_repeat("b",32),"name"=>"Vindrutebyte","unit"=>"st","price"=>750.0,"fixed_price"=>true,"aliases"=>["Rutbyte"]]]; echo "VOICE_HTML:".base64_encode(render_invoice(false,true,[],"sv"));';
const result = execFileSync('npx',['--yes','@php-wasm/cli','-r',php],{encoding:'utf8'});
assert(result.includes('VOICE_HTML:'), result);
const html = Buffer.from(result.split('VOICE_HTML:')[1], 'base64').toString('utf8');
const flush = () => new Promise(resolve=>setImmediate(resolve));
(async () => {
 const dom = new JSDOM(html,{url:'https://example.test/pwa-underlag.php',runScripts:'outside-only',pretendToBeVisual:true});
 const w=dom.window, doc=w.document, get=s=>doc.querySelector(s), calls=[], spoken=[];
 const profile={learning:true,auto:false,reviewed:5,corrections:[{heard:'<b>fel</b>',approved:'Gamla ord'}],share_learning:false,numeric_learning:false,numeric_corrections:[]};
 let engine, pendingSpeech=null;
 class Recognition { constructor(){engine=this;} start(){} stop(){this.onend();} abort(){} }
 w.SpeechRecognition=Recognition; w.InvoiceVoice=voice; w.confirm=()=>true;
 w.matchMedia=()=>({matches:true,addListener(){},removeListener(){},addEventListener(){}});
 w.SpeechSynthesisUtterance=function(text){this.text=text;};
 w.speechSynthesis={cancel(){pendingSpeech=null;},speak(utterance){spoken.push(utterance.text);pendingSpeech=utterance;}};
 w.fetch=async(url,options)=>{
  const p=options.body; if(!p || p.get('action')!=='voice_learning')return {ok:true,json:async()=>({ok:true})};
  const op=p.get('operation');calls.push({op,keys:Array.from(p.keys())});
  if(op==='dictionary_update'||op==='dictionary_delete') {
   const i=profile.corrections.findIndex(x=>x.heard===p.get('heard') && x.approved===p.get('old_approved'));
   if(i<0)return {ok:false,json:async()=>({code:'stale'})};
   if(op==='dictionary_update')profile.corrections[i].approved=p.get('approved');else profile.corrections.splice(i,1);
  }
  return {ok:true,json:async()=>({ok:true,profile:structuredClone(profile)})};
 };
 for(const script of doc.querySelectorAll('script:not([src])'))w.eval(script.textContent);
 await flush();
 assert.equal(get('#voice-dictionary-list b'),null,'Untrusted words render as text');
 assert.match(get('#voice-dictionary-list').textContent,/<b>fel<\/b>/);
 let entry=get('.voice-dictionary-entry'); entry.querySelector('input').value='Ny beskrivning';entry.querySelector('button').click();await flush();await flush();
 assert.equal(profile.corrections[0].approved,'Ny beskrivning');assert.equal(profile.reviewed,5,'Editing is not recognition');
 entry=get('.voice-dictionary-entry');entry.querySelectorAll('button')[1].click();await flush();await flush();
 assert.equal(profile.corrections.length,0);assert.match(get('#voice-dictionary-status').textContent,/uppdaterades|Inga/);
 assert(calls.every(c=>!c.keys.includes('user_id')),'No owner ID can be posted');
 function say(text){get('#voice-start').click();const item=[{transcript:text}];item.isFinal=true;engine.onresult({results:[item]});engine.onend();}
 say('2st Rutbyte 900 kr st');
 assert.equal(get('#voice-uncertainty').hidden,false);assert.match(get('#voice-uncertainty').textContent,/Beskrivning|Pris/);
 assert(get('#voice-price').classList.contains('voice-needs-review'));
 get('#voice-clear').click();assert.equal(get('#voice-uncertainty').hidden,true);
 say('till antal');assert.equal(doc.activeElement.id,'voice-qty');
 say('till pris');assert.equal(doc.activeElement.id,'voice-price');
 assert.equal(get('.invoice-item-name').value,'','Navigation commands never submit or add a value');
 get('#invoice-customer').value='Kund AB';get('#invoice-reference').value='Referens 12';get('#invoice-description').value='Lång beskrivning '.repeat(40);
 get('.invoice-item-name').value='Arbete';get('.invoice-item-qty').value='2';get('.invoice-item-price').value='125';
 get('#voice-read').click();assert.equal(get('#voice-read-stop').hidden,false);
 const previousEngine=engine; get('#voice-start').click();assert.equal(engine,previousEngine,'Microphone cannot start during readout');
 while(pendingSpeech) { const current=pendingSpeech;pendingSpeech=null;current.onend(); }
 const text=spoken.join('');assert.match(text,/Kund AB/);assert.match(text,/Referens 12/);assert(text.includes('Lång beskrivning '.repeat(40).trim()));
 assert.match(text,/Rad 1: Arbete, antal 2/);assert.match(text,/250\.00 kronor/);assert.equal(get('#voice-read-stop').hidden,true);
 get('#voice-start').click();const beforeBlocked=spoken.length;get('#voice-read').click();assert.equal(spoken.length,beforeBlocked,'Reading is blocked while microphone is active');get('#voice-cancel').click();
 get('#voice-read').click();assert.equal(get('#voice-read-stop').hidden,false);get('#voice-read-stop').click();assert.equal(get('#voice-read-stop').hidden,true);assert.match(get('#voice-status').textContent,/stoppades/);
 const before=spoken.length;say('läs upp underlaget');assert(spoken.length>before,'Voice command triggers reading only');get('#voice-read-stop').click();
 assert(!calls.some(c=>['save_invoice','send'].includes(c.op)),'Voice features do not save or send');
 dom.window.close();console.log('PASS: voice dictionary, commands, review markers and whole-record reading DOM checks.');
})().catch(error=>{console.error(error);process.exitCode=1;});

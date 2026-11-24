'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { JSDOM, VirtualConsole } = require('jsdom');
const source = fs.readFileSync('index.php', 'utf8');
const css = fs.readFileSync('assets/portal.css', 'utf8');
const early = source.match(/<script>\s*([\s\S]*?var savedWidth[\s\S]*?)<\/script>/)[1];
const prefPos = source.indexOf("var preferences = document.getElementById('portal-ui-preferences')");
const start = source.lastIndexOf('(function () {', prefPos);
const end = source.indexOf('})();', prefPos) + '})();'.length;
const preferences = source.slice(start, end);
assert(!preferences.includes('<?'), 'Preference script runs without server-side interpolation');
const html = `<!doctype html><html lang="en"><head><meta name="theme-color" content="#14343b"><style>${css}</style></head><body>
<details id="portal-ui-preferences"><summary>Display</summary><div>
<select id="portal-theme-select"><option value="system">System</option><option value="light">Light</option><option value="dark">Dark</option></select>
<select id="portal-density-select"><option value="comfortable">Comfortable</option><option value="compact">Compact</option></select>
<select id="portal-width-select"><option value="standard">Standard</option><option value="wide">Wide</option></select></div></details>
<nav class="tabs-wrap"><button id="portal-nav-toggle" aria-expanded="false"></button><div id="portal-main-menu"><a href="?tab=invoice">Invoice</a></div></nav>
<div id="portal-network-status"><strong id="portal-network-title"></strong><span id="portal-network-detail"></span></div></body></html>`;
function setup(values={}, dark=false, blocked=false) {
  const errors=[]; const vc=new VirtualConsole(); vc.on('jsdomError', e=>errors.push(e.message));
  const dom=new JSDOM(html,{url:'https://example.test/pwa-underlag.php',runScripts:'outside-only',pretendToBeVisual:true,virtualConsole:vc});
  const w=dom.window, callbacks={};
  w.matchMedia=query=>({matches:query.includes('dark') ? dark : true,addEventListener(_event,cb){callbacks[query]=cb;}});
  if (blocked) Object.defineProperty(w,'localStorage',{get(){throw new Error('blocked')}});
  else Object.entries(values).forEach(([k,v])=>w.localStorage.setItem('tadega.portal.'+k,v));
  w.eval(early);w.eval(preferences);
  return {dom,w,root:w.document.documentElement,get:id=>w.document.getElementById(id),errors,callbacks};
}
{
 const c=setup({theme:'dark',density:'compact',width:'wide'});
 assert.equal(c.errors.length,0,'Shared CSS parses');
 assert.equal(c.root.dataset.theme,'dark');assert.equal(c.root.dataset.density,'compact');assert.equal(c.root.dataset.width,'wide');
 assert.equal(c.get('portal-width-select').value,'wide');
 c.get('portal-width-select').value='standard';c.get('portal-width-select').dispatchEvent(new c.w.Event('change'));
 assert.equal(c.root.dataset.width,'standard');assert.equal(c.w.localStorage.getItem('tadega.portal.width'),'standard');
 c.get('portal-theme-select').value='light';c.get('portal-theme-select').dispatchEvent(new c.w.Event('change'));
 assert.equal(c.root.dataset.theme,'light');assert.equal(c.w.localStorage.getItem('tadega.portal.theme'),'light');
 c.get('portal-nav-toggle').click();assert.equal(c.get('portal-nav-toggle').getAttribute('aria-expanded'),'true');assert.equal(c.get('portal-nav-toggle').getAttribute('aria-label'),'Close main menu');
 c.get('portal-nav-toggle').dispatchEvent(new c.w.KeyboardEvent('keydown',{key:'Escape',bubbles:true}));assert.equal(c.get('portal-nav-toggle').getAttribute('aria-expanded'),'false');
 assert.equal(c.w.localStorage.length,3,'Only visual preferences are persisted');c.dom.window.close();
}
{
 const c=setup({width:'bad',theme:'invalid'},true);
 assert.equal(c.root.dataset.width,'standard');assert.equal(c.root.dataset.theme,'dark','System preference is respected');c.dom.window.close();
}
{
 const c=setup({},false,true);assert.equal(c.root.dataset.width,'standard');assert.equal(c.root.dataset.theme,'light');
 c.get('portal-width-select').value='wide';c.get('portal-width-select').dispatchEvent(new c.w.Event('change'));assert.equal(c.root.dataset.width,'wide','Settings still work if storage is blocked');c.dom.window.close();
}
assert(source.indexOf("$tabIcons = [") < source.indexOf('<!doctype html>'),'Icons are available even when scoped PWA navigation is hidden');
assert(source.includes("'calendar' => '<svg"));
assert(css.includes(':root[data-width="wide"]') && css.includes('prefers-reduced-motion:reduce'));
function luminance(hex) {
 const rgb=hex.match(/../g).map(x=>parseInt(x,16)/255).map(v=>v<=0.04045?v/12.92:((v+0.055)/1.055)**2.4);
 return .2126*rgb[0]+.7152*rgb[1]+.0722*rgb[2];
}
function contrast(a,b) { const x=luminance(a),y=luminance(b);return (Math.max(x,y)+.05)/(Math.min(x,y)+.05); }
for (const [text,bg] of [['ffffff','08786e'],['e6efed','1b2d2c'],['bdceca','1b2d2c'],['d5e9e1','203a35']]) assert(contrast(text,bg)>=4.5, `Text contrast ${text}/${bg}`);
assert(css.includes('A dark screen preference must never make a printed document dark'));
assert(!/backdrop-filter\s*:\s*blur/i.test(css), 'Shared navigation must not blur its background');
for (const file of ['tabs/invoice.php','tabs/protocol.php','tabs/settings.php']) {
 const content=fs.readFileSync(file,'utf8');
 assert(!/backdrop-filter\s*:\s*blur/i.test(content), `${file} must not blur working surfaces`);
}
const calendar=fs.readFileSync('tabs/calendar.php','utf8');
assert(calendar.includes('calendar-planner') && calendar.includes('calendar-heading-icon') && calendar.includes('max-width:380px'), 'Calendar has scalable layout and vector icons');
assert(css.includes('Screen readability: vector icons') && css.includes('letter-spacing:normal') && css.includes('pointer:coarse'), 'Crisp screen-only typography, vector icons and touch controls exist');
assert(css.includes('forced-colors:active') && css.includes('#invoice-shared-learning form') && css.includes('grid-template-columns:minmax(0,1fr)'), 'Responsive and high-contrast controls exist');


console.log('PASS: visual preferences, responsive navigation and PWA icon checks.');

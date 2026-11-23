'use strict';
// The portal requires a connection for every record and form. Never cache
// authenticated responses or customer data; an offline navigation receives
// only this generic, hard-coded message.
self.addEventListener('install', function (event) {
    event.waitUntil(self.skipWaiting());
});
self.addEventListener('activate', function (event) {
    event.waitUntil(self.clients.claim());
});
self.addEventListener('fetch', function (event) {
    if (event.request.method !== 'GET' || event.request.mode !== 'navigate' || new URL(event.request.url).origin !== self.location.origin) return;
    event.respondWith(fetch(event.request).catch(function () {
        return new Response('<!doctype html><html lang="sv"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Offline · Dokumentportalen</title><main><h1>Ingen nätverksanslutning</h1><p>Dokumentportalen behöver nätverk för att visa och spara uppgifter. Anslut igen och ladda om sidan.</p></main></html>', {
            status: 503,
            headers: { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' }
        });
    }));
});

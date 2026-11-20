<?php
/**
 * Service worker (mode hors ligne) : garde en cache les pages et documents consultés par les élèves,
 * pour les relire sans réseau. L'espace enseignant n'est jamais mis en cache.
 */
require_once __DIR__ . '/config.php';
header('Content-Type: text/javascript; charset=UTF-8');
header('Cache-Control: no-cache');
$v = preg_replace('/[^0-9.]/', '', TPW_VERSION);
?>
'use strict';
var VERSION = 'tpweb-<?= $v ?>';
var SHELL = VERSION + '-shell', PAGES = VERSION + '-pages', DOCS = VERSION + '-docs';
var SHELL_FILES = ['assets/style.css?v=<?= $v ?>', 'assets/app.js?v=<?= $v ?>', 'assets/favicon.svg', 'assets/icon-192.png', 'offline.html'];
var MAX_PAGES = 60, MAX_DOCS = 40;

self.addEventListener('install', function (e) {
  e.waitUntil(caches.open(SHELL).then(function (c) { return c.addAll(SHELL_FILES); }).then(function () { return self.skipWaiting(); }));
});
self.addEventListener('activate', function (e) {
  e.waitUntil(caches.keys().then(function (keys) {
    return Promise.all(keys.filter(function (k) { return k.indexOf(VERSION) !== 0; }).map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});

function trim(name, max) {
  return caches.open(name).then(function (c) {
    return c.keys().then(function (keys) { if (keys.length > max) return c.delete(keys[0]).then(function () { return trim(name, max); }); });
  });
}
function networkFirst(req, cacheName, max) {
  return fetch(req).then(function (res) {
    if (res && res.ok && res.type === 'basic') {
      var copy = res.clone();
      caches.open(cacheName).then(function (c) { c.put(req, copy); trim(cacheName, max); });
    }
    return res;
  }).catch(function () {
    return caches.match(req).then(function (hit) {
      if (hit) return hit;
      if (req.mode === 'navigate') return caches.match('offline.html');
      return new Response('Hors ligne', { status: 503, headers: { 'Content-Type': 'text/plain; charset=UTF-8' } });
    });
  });
}

self.addEventListener('fetch', function (e) {
  var req = e.request;
  if (req.method !== 'GET') return;
  var url = new URL(req.url);
  if (url.origin !== location.origin) return;
  var page = url.pathname.split('/').pop();
  // Jamais de cache : espace enseignant, connexion ENT, projection, annotation, mise à jour.
  if (/^(admin|cas|present|annotate|copie|sw|manifest|cron|logo)\.php$/.test(page)) return;
  if (url.pathname.indexOf('/assets/') !== -1) {
    e.respondWith(caches.match(req).then(function (hit) { return hit || fetch(req); }));
    return;
  }
  if (page === 'file.php' || page === 'view.php') { e.respondWith(networkFirst(req, DOCS, MAX_DOCS)); return; }
  if (req.mode === 'navigate' || page === 'index.php' || page === 'preview.php' || page === 'quiz.php' || page === '') {
    e.respondWith(networkFirst(req, PAGES, MAX_PAGES));
  }
});

/* Port Said Hub — Service Worker
   الاستراتيجية: الشبكة أولًا (Network First) لأي صفحة، مع نسخة احتياطية offline.
   التحديث: كل تعديل في هذا الملف يخلي المتصفح يحمّل نسخة Service Worker جديدة تلقائيًا؛
   الصفحة المفتوحة بتكتشف التحديث وتعمل لنفسها ريفريش لوحدها (شوف الكود في نهاية index.html). */
const V = 'psh-v5';
const SHELL = ['./', 'assets/logo/icon-192.png', 'assets/logo/icon.png'];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(V).then(c => c.addAll(SHELL)).catch(() => {}));
  self.skipWaiting();   // فعّل النسخة الجديدة فورًا من غير استنى إغلاق كل التابات
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys()
      .then(k => Promise.all(k.filter(x => x !== V).map(x => caches.delete(x))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', e => {
  const r = e.request, u = new URL(r.url);
  if (r.method !== 'GET' || u.origin !== location.origin || u.pathname.indexOf('/api') > -1 || u.search.indexOf('r=') > -1) return;
  e.respondWith(
    fetch(r, { cache: 'no-store' })
      .then(res => { if (res.ok) { const cp = res.clone(); caches.open(V).then(c => c.put(r, cp)); } return res; })
      .catch(() => caches.match(r).then(m => m || caches.match('./')))
  );
});

/* لما صفحة مفتوحة تطلب تحديث فوري (مثلًا بعد ما نكتشف نسخة جديدة) */
self.addEventListener('message', e => { if (e.data === 'skipWaiting') self.skipWaiting(); });

/*
 * Service worker de la PWA Clientèle Group ERP.
 *
 * - Pages : réseau d'abord, puis la coquille en cache si le poste est hors ligne.
 *   Toutes les adresses (/location/reservations/...) partagent la même coquille.
 * - Fichiers compilés et images : cache d'abord, ils sont versionnés par leur nom.
 * - API : jamais mise en cache. Les données métier restent sous le contrôle du serveur.
 */
const CACHE_NAME = 'clientele-group-erp-v0.5.1-alpha.1'
const SHELL = '/index.html'
const PRECACHE = ['/', SHELL, '/manifest.webmanifest', '/icon.svg', '/brand/clientele-group-logo.webp']

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE_NAME).then((cache) => cache.addAll(PRECACHE)))
  self.skipWaiting()
})

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(
      keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key)),
    )),
  )
  self.clients.claim()
})

self.addEventListener('fetch', (event) => {
  const request = event.request
  const url = new URL(request.url)

  if (request.method !== 'GET' || url.origin !== self.location.origin || url.pathname.startsWith('/api/')) {
    return
  }

  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request)
        .then((response) => {
          const copy = response.clone()
          void caches.open(CACHE_NAME).then((cache) => cache.put(SHELL, copy))
          return response
        })
        .catch(() => caches.match(SHELL).then((cached) => cached || Response.error())),
    )
    return
  }

  event.respondWith(
    caches.match(request).then((cached) => cached || fetch(request).then((response) => {
      if (response.ok) {
        const copy = response.clone()
        void caches.open(CACHE_NAME).then((cache) => cache.put(request, copy))
      }
      return response
    })),
  )
})

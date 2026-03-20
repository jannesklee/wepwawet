<?php
// Minimal pass-through service worker — satisfies Chrome's PWA install requirement.
// No caching: all requests go straight to the network.
header('Content-Type: application/javascript');
?>
self.addEventListener('fetch', event => {
	event.respondWith(fetch(event.request))
})

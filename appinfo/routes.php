<?php

return [
	'routes' => [
		// Pages
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
		['name' => 'page#join', 'url' => '/join/{token}', 'verb' => 'GET'],
		['name' => 'page#view', 'url' => '/view/{token}', 'verb' => 'GET'],

		// PWA manifests + service worker
		['name' => 'page#manifest', 'url' => '/manifest.webmanifest', 'verb' => 'GET'],
		['name' => 'page#viewerManifest', 'url' => '/view/{token}/manifest.webmanifest', 'verb' => 'GET'],
		['name' => 'page#joinManifest', 'url' => '/join/{token}/manifest.webmanifest', 'verb' => 'GET'],
		['name' => 'page#serviceWorker', 'url' => '/sw.js', 'verb' => 'GET'],

		// Group management
		['name' => 'group#accept', 'url' => '/join/{token}/accept', 'verb' => 'POST'],
		['name' => 'group#positions', 'url' => '/group/{id}/positions', 'verb' => 'GET'],

		// Position updates
		['name' => 'position#update', 'url' => '/position', 'verb' => 'POST'],
		['name' => 'position#guestUpdate', 'url' => '/guest/{token}', 'verb' => 'POST'],

		// Timed share links (Mode 2)
		['name' => 'share#create', 'url' => '/share', 'verb' => 'POST'],
		['name' => 'share#revoke', 'url' => '/share/{id}/revoke', 'verb' => 'POST'],
		['name' => 'share#list', 'url' => '/shares', 'verb' => 'GET'],
		['name' => 'share#position', 'url' => '/view/{token}/position', 'verb' => 'GET'],
	],
];

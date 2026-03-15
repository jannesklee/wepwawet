<?php

return [
	'routes' => [
		// Pages
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
		['name' => 'page#join', 'url' => '/join/{token}', 'verb' => 'GET'],

		// Group management
		['name' => 'group#accept', 'url' => '/join/{token}/accept', 'verb' => 'POST'],
		['name' => 'group#positions', 'url' => '/group/{id}/positions', 'verb' => 'GET'],

		// Position updates
		['name' => 'position#update', 'url' => '/position', 'verb' => 'POST'],
		['name' => 'position#guestUpdate', 'url' => '/guest/{token}', 'verb' => 'POST'],
	],
];

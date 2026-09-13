<?php

namespace OCA\Sopdet\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\ContentSecurityPolicy;

class Application extends App implements IBootstrap {

	public const APP_ID = 'sopdet';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
	}

	public function boot(IBootContext $context): void {
		$cspManager = $this->getContainer()->get(\OCP\Security\IContentSecurityPolicyManager::class);

		$csp = new ContentSecurityPolicy();
		// MapLibre GL fetches raster tiles via fetch() in a web worker (connect-src).
		// The wildcard *.tile.openstreetmap.org covers a/b/c subdomains used for
		// load balancing; the bare domain is needed as a fallback.
		$csp->addAllowedConnectDomain('https://tile.openstreetmap.org');
		$csp->addAllowedConnectDomain('https://*.tile.openstreetmap.org');
		// Some browsers/MapLibre versions decode tiles into <img> elements (img-src).
		$csp->addAllowedImageDomain('https://tile.openstreetmap.org');
		$csp->addAllowedImageDomain('https://*.tile.openstreetmap.org');
		// MapLibre GL uses a web worker created from a blob URL
		$csp->addAllowedWorkerSrcDomain('blob:');
		// Older browsers may fall back to child-src for worker origin checks
		$csp->addAllowedChildSrcDomain('blob:');

		$cspManager->addDefaultPolicy($csp);
	}
}

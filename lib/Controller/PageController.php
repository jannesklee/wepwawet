<?php

namespace OCA\Wepwawet\Controller;

use OCA\Wepwawet\AppInfo\Application;
use OCA\Wepwawet\Db\Group;
use OCA\Wepwawet\Db\GroupMapper;
use OCA\Wepwawet\Db\GroupMember;
use OCA\Wepwawet\Db\GroupMemberMapper;
use OCA\Wepwawet\Db\ShareMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;

class PageController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private GroupMapper $groupMapper,
		private GroupMemberMapper $groupMemberMapper,
		private ShareMapper $shareMapper,
		private IInitialState $initialState,
		private IURLGenerator $urlGenerator,
		private IUserManager $userManager,
		private IConfig $config,
		private IL10N $l,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		$currentUser = $this->userManager->get($this->userId);
		$displayName = $currentUser !== null ? $currentUser->getDisplayName() : $this->userId;

		// Ensures a first-time visitor has at least one group to start with;
		// the web app fetches the actual (possibly multi-group) list client-side
		// from GroupController::list() rather than through this initial state.
		$this->resolveOrCreateGroup($displayName);

		$state = [
			'currentUserId' => $this->userId,
			'currentUserDisplayName' => $displayName,
			'currentUserAvatarUrl' => '/index.php/avatar/' . urlencode($this->userId) . '/64',
			'groupsUrl' => $this->urlGenerator->linkToRoute('wepwawet.group.list'),
			'updateUrl' => $this->urlGenerator->linkToRoute('wepwawet.position.update'),
			'swUrl' => $this->urlGenerator->linkToRoute('wepwawet.page.serviceWorker'),
		];

		$this->initialState->provideInitialState('wepwawet-state', $state);

		\OCP\Util::addHeader('link', ['rel' => 'manifest', 'href' => $this->urlGenerator->linkToRoute('wepwawet.page.manifest')]);
		\OCP\Util::addHeader('meta', ['name' => 'theme-color', 'content' => '#0082c9']);
		// Nextcloud hardcodes `Referrer-Policy: no-referrer` in lib/base.php, which
		// strips the Referer header on OSM tile requests - OSM's tile usage policy
		// rejects requests with no Referer at all. A <meta name="referrer"> element
		// can relax this for the document's own outgoing requests even though the
		// HTTP header itself can't be overridden from app code.
		\OCP\Util::addHeader('meta', ['name' => 'referrer', 'content' => 'strict-origin-when-cross-origin']);

		$response = new TemplateResponse(Application::APP_ID, 'main');
		return $response;
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'wepwawetJoin')]
	public function join(string $token): TemplateResponse {
		try {
			$group = $this->groupMapper->findByToken($token);
		} catch (DoesNotExistException $e) {
			$response = new TemplateResponse('', 'error', [
				'errors' => [['error' => $this->l->t('Invite link not found')]],
			], TemplateResponse::RENDER_AS_ERROR);
			$response->setStatus(Http::STATUS_NOT_FOUND);
			$response->throttle(['join_not_found' => $token]);
			return $response;
		}

		$ownerUser = $this->userManager->get($group->getOwnerUserId());
		$ownerDisplayName = $ownerUser !== null ? $ownerUser->getDisplayName() : $group->getOwnerUserId();

		$userDisplayName = null;
		if ($this->userId !== null) {
			$currentUser = $this->userManager->get($this->userId);
			$userDisplayName = $currentUser !== null ? $currentUser->getDisplayName() : $this->userId;
		}

		$response = new PublicTemplateResponse(Application::APP_ID, 'join', [
			'group_name' => $group->getName(),
			'owner_display_name' => $ownerDisplayName,
			'guest_update_url' => $this->urlGenerator->linkToRoute('wepwawet.position.guestUpdate', ['token' => $token]),
			'guest_positions_url' => $this->urlGenerator->linkToRoute('wepwawet.group.guestPositions', ['token' => $token]),
			'accept_url' => $this->urlGenerator->linkToRoute('wepwawet.group.accept', ['token' => $token]),
			'main_url' => $this->userId !== null
				? $this->urlGenerator->linkToRoute('wepwawet.page.index')
				: null,
			'user_id' => $this->userId,
			'user_display_name' => $userDisplayName,
			'debug' => $this->config->getSystemValueBool('debug', false),
			'sw_url' => $this->urlGenerator->linkToRoute('wepwawet.page.serviceWorker'),
		]);
		\OCP\Util::addHeader('link', ['rel' => 'manifest', 'href' => $this->urlGenerator->linkToRoute('wepwawet.page.joinManifest', ['token' => $token])]);
		\OCP\Util::addHeader('link', ['rel' => 'apple-touch-icon', 'href' => $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('wepwawet', 'apple-touch-icon.png'))]);
		\OCP\Util::addHeader('meta', ['name' => 'theme-color', 'content' => '#0082c9']);
		// Nextcloud hardcodes `Referrer-Policy: no-referrer` in lib/base.php, which
		// strips the Referer header on OSM tile requests - OSM's tile usage policy
		// rejects requests with no Referer at all. A <meta name="referrer"> element
		// can relax this for the document's own outgoing requests even though the
		// HTTP header itself can't be overridden from app code.
		\OCP\Util::addHeader('meta', ['name' => 'referrer', 'content' => 'strict-origin-when-cross-origin']);

		$response->setHeaderTitle($this->l->t('Share your location'));
		$response->setFooterVisible(false);
		return $response;
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function view(string $token): TemplateResponse {
		try {
			$share = $this->shareMapper->findByToken($token);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			$response = new TemplateResponse('', 'error', [
				'errors' => [['error' => $this->l->t('Share link not found')]],
			], TemplateResponse::RENDER_AS_ERROR);
			$response->setStatus(Http::STATUS_NOT_FOUND);
			return $response;
		}

		if ($share->getExpiresAt() !== null && $share->getExpiresAt() < time()) {
			$response = new TemplateResponse('', 'error', [
				'errors' => [['error' => $this->l->t('This share link has expired')]],
			], TemplateResponse::RENDER_AS_ERROR);
			$response->setStatus(Http::STATUS_GONE);
			return $response;
		}

		$ownerUser = $this->userManager->get($share->getOwnerUserId());
		$ownerDisplayName = $ownerUser !== null ? $ownerUser->getDisplayName() : $share->getOwnerUserId();

		$state = [
			'ownerDisplayName' => $ownerDisplayName,
			'ownerAvatarUrl' => '/index.php/avatar/' . urlencode($share->getOwnerUserId()) . '/64',
			'positionUrl' => $this->urlGenerator->linkToRoute('wepwawet.share.position', ['token' => $token]),
			'expiresAt' => $share->getExpiresAt(),
			'swUrl' => $this->urlGenerator->linkToRoute('wepwawet.page.serviceWorker'),
		];

		$this->initialState->provideInitialState('wepwawet-viewer-state', $state);

		\OCP\Util::addHeader('link', ['rel' => 'manifest', 'href' => $this->urlGenerator->linkToRoute('wepwawet.page.viewerManifest', ['token' => $token])]);
		\OCP\Util::addHeader('link', ['rel' => 'apple-touch-icon', 'href' => $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('wepwawet', 'apple-touch-icon.png'))]);
		\OCP\Util::addHeader('meta', ['name' => 'theme-color', 'content' => '#0082c9']);
		// Nextcloud hardcodes `Referrer-Policy: no-referrer` in lib/base.php, which
		// strips the Referer header on OSM tile requests - OSM's tile usage policy
		// rejects requests with no Referer at all. A <meta name="referrer"> element
		// can relax this for the document's own outgoing requests even though the
		// HTTP header itself can't be overridden from app code.
		\OCP\Util::addHeader('meta', ['name' => 'referrer', 'content' => 'strict-origin-when-cross-origin']);

		$response = new PublicTemplateResponse(Application::APP_ID, 'viewer', []);
		$response->setHeaderTitle($ownerDisplayName . ' — ' . $this->l->t('Live location'));
		$response->setFooterVisible(false);
		return $response;
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function serviceWorker(): TemplateResponse {
		$response = new TemplateResponse(Application::APP_ID, 'sw', [], TemplateResponse::RENDER_AS_BLANK);
		$response->addHeader('Content-Type', 'application/javascript');
		$response->addHeader('Service-Worker-Allowed', $this->urlGenerator->linkToRoute('wepwawet.page.index'));
		return $response;
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function manifest(): DataResponse {
		return $this->buildManifest(
			name: 'Wepwawet',
			startUrl: $this->urlGenerator->linkToRoute('wepwawet.page.index'),
			scope: $this->urlGenerator->linkToRoute('wepwawet.page.index'),
		);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function viewerManifest(string $token): DataResponse {
		try {
			$share = $this->shareMapper->findByToken($token);
			$ownerUser = $this->userManager->get($share->getOwnerUserId());
			$name = ($ownerUser !== null ? $ownerUser->getDisplayName() : $share->getOwnerUserId()) . ' — Live location';
		} catch (DoesNotExistException $e) {
			$name = 'Wepwawet';
		}
		$url = $this->urlGenerator->linkToRoute('wepwawet.page.view', ['token' => $token]);
		return $this->buildManifest(name: $name, startUrl: $url, scope: $url);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function joinManifest(string $token): DataResponse {
		$url = $this->urlGenerator->linkToRoute('wepwawet.page.join', ['token' => $token]);
		return $this->buildManifest(name: 'Share location', startUrl: $url, scope: $url);
	}

	private function buildManifest(string $name, string $startUrl, string $scope): DataResponse {
		$iconBase = $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('wepwawet', ''));
		$manifest = [
			'name' => $name,
			'short_name' => 'Wepwawet',
			'description' => 'Live location sharing',
			'start_url' => $startUrl,
			'scope' => $scope,
			'display' => 'standalone',
			'background_color' => '#ffffff',
			'theme_color' => '#0082c9',
			'icons' => [
				['src' => $iconBase . 'icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
				['src' => $iconBase . 'icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
				['src' => $iconBase . 'app.svg',      'sizes' => 'any',     'type' => 'image/svg+xml'],
			],
		];
		$response = new DataResponse($manifest);
		$response->addHeader('Content-Type', 'application/manifest+json');
		return $response;
	}

	/**
	 * Find the group this user owns, or one they've joined. Auto-creates if neither exists.
	 */
	private function resolveOrCreateGroup(string $displayName): Group {
		// Prefer owned group
		$owned = $this->groupMapper->findByOwner($this->userId);
		if (count($owned) > 0) {
			return $owned[0];
		}

		// Fall back to a joined group
		foreach ($this->groupMemberMapper->findByUser($this->userId) as $membership) {
			try {
				return $this->groupMapper->find($membership->getGroupId());
			} catch (DoesNotExistException $e) {
				continue;
			}
		}

		// First visit: auto-create a group and add the owner as a member
		$group = new Group();
		$group->setName($displayName . "'s location");
		$group->setOwnerUserId($this->userId);
		$group->setToken(bin2hex(random_bytes(16)));
		$group->setCreatedAt(time());
		$group = $this->groupMapper->insert($group);

		$member = new GroupMember();
		$member->setGroupId($group->getId());
		$member->setUserId($this->userId);
		$member->setJoinedAt(time());
		$this->groupMemberMapper->insert($member);

		return $group;
	}
}

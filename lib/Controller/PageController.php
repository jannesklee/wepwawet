<?php

namespace OCA\LocShare\Controller;

use OCA\LocShare\AppInfo\Application;
use OCA\LocShare\Db\Group;
use OCA\LocShare\Db\GroupMapper;
use OCA\LocShare\Db\GroupMember;
use OCA\LocShare\Db\GroupMemberMapper;
use OCA\LocShare\Db\ShareMapper;
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

		$group = $this->resolveOrCreateGroup($displayName);

		$state = [
			'currentUserId' => $this->userId,
			'currentUserDisplayName' => $displayName,
			'currentUserAvatarUrl' => '/index.php/avatar/' . urlencode($this->userId) . '/64',
			'groupId' => $group->getId(),
			'groupToken' => $group->getToken(),
			'inviteUrl' => rtrim($this->urlGenerator->getAbsoluteURL('/'), '/')
				. $this->urlGenerator->linkToRoute('locshare.page.join', ['token' => $group->getToken()]),
			'positionsUrl' => $this->urlGenerator->linkToRoute('locshare.group.positions', ['id' => $group->getId()]),
			'updateUrl' => $this->urlGenerator->linkToRoute('locshare.position.update'),
			'swUrl' => $this->urlGenerator->linkToRoute('locshare.page.serviceWorker'),
		];

		$this->initialState->provideInitialState('locshare-state', $state);

		\OCP\Util::addHeader('link', ['rel' => 'manifest', 'href' => $this->urlGenerator->linkToRoute('locshare.page.manifest')]);
		\OCP\Util::addHeader('meta', ['name' => 'theme-color', 'content' => '#0082c9']);

		return new TemplateResponse(Application::APP_ID, 'main');
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'locshareJoin')]
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
			'guest_update_url' => $this->urlGenerator->linkToRoute('locshare.position.guestUpdate', ['token' => $token]),
			'accept_url' => $this->urlGenerator->linkToRoute('locshare.group.accept', ['token' => $token]),
			'main_url' => $this->userId !== null
				? $this->urlGenerator->linkToRoute('locshare.page.index')
				: null,
			'user_id' => $this->userId,
			'user_display_name' => $userDisplayName,
			'debug' => $this->config->getSystemValueBool('debug', false),
			'sw_url' => $this->urlGenerator->linkToRoute('locshare.page.serviceWorker'),
		]);
		\OCP\Util::addHeader('link', ['rel' => 'manifest', 'href' => $this->urlGenerator->linkToRoute('locshare.page.joinManifest', ['token' => $token])]);
		\OCP\Util::addHeader('link', ['rel' => 'apple-touch-icon', 'href' => $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('locshare', 'apple-touch-icon.png'))]);
		\OCP\Util::addHeader('meta', ['name' => 'theme-color', 'content' => '#0082c9']);

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
			'positionUrl' => $this->urlGenerator->linkToRoute('locshare.share.position', ['token' => $token]),
			'expiresAt' => $share->getExpiresAt(),
			'swUrl' => $this->urlGenerator->linkToRoute('locshare.page.serviceWorker'),
		];

		$this->initialState->provideInitialState('locshare-viewer-state', $state);

		\OCP\Util::addHeader('link', ['rel' => 'manifest', 'href' => $this->urlGenerator->linkToRoute('locshare.page.viewerManifest', ['token' => $token])]);
		\OCP\Util::addHeader('link', ['rel' => 'apple-touch-icon', 'href' => $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('locshare', 'apple-touch-icon.png'))]);
		\OCP\Util::addHeader('meta', ['name' => 'theme-color', 'content' => '#0082c9']);

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
		$response->addHeader('Service-Worker-Allowed', $this->urlGenerator->linkToRoute('locshare.page.index'));
		return $response;
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function manifest(): DataResponse {
		return $this->buildManifest(
			name: 'LocShare',
			startUrl: $this->urlGenerator->linkToRoute('locshare.page.index'),
			scope: $this->urlGenerator->linkToRoute('locshare.page.index'),
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
			$name = 'LocShare';
		}
		$url = $this->urlGenerator->linkToRoute('locshare.page.view', ['token' => $token]);
		return $this->buildManifest(name: $name, startUrl: $url, scope: $url);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function joinManifest(string $token): DataResponse {
		$url = $this->urlGenerator->linkToRoute('locshare.page.join', ['token' => $token]);
		return $this->buildManifest(name: 'Share location', startUrl: $url, scope: $url);
	}

	private function buildManifest(string $name, string $startUrl, string $scope): DataResponse {
		$iconBase = $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('locshare', ''));
		$manifest = [
			'name' => $name,
			'short_name' => 'LocShare',
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

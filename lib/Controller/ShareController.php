<?php

namespace OCA\Wepwawet\Controller;

use OCA\Wepwawet\Db\Share;
use OCA\Wepwawet\Db\ShareMapper;
use OCA\Wepwawet\Db\PositionMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;

class ShareController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private ShareMapper $shareMapper,
		private PositionMapper $positionMapper,
		private IUserManager $userManager,
		private IURLGenerator $urlGenerator,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Create a timed share link. duration=0 means no expiry.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function create(int $duration = 0): DataResponse {
		$share = new Share();
		$share->setOwnerUserId($this->userId);
		$share->setToken(bin2hex(random_bytes(16)));
		$share->setCreatedAt(time());
		$share->setExpiresAt($duration > 0 ? time() + $duration * 60 : null);
		$share = $this->shareMapper->insert($share);

		return new DataResponse($this->formatShare($share));
	}

	/**
	 * Revoke a share early.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function revoke(int $id): DataResponse {
		try {
			$share = $this->shareMapper->find($id);
		} catch (DoesNotExistException $e) {
			return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		if ($share->getOwnerUserId() !== $this->userId) {
			return new DataResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$this->shareMapper->delete($share);

		return new DataResponse(['ok' => true]);
	}

	/**
	 * List active shares for the current user.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function list(): DataResponse {
		$shares = $this->shareMapper->findActiveByUser($this->userId);
		return new DataResponse(array_map(fn (Share $s) => $this->formatShare($s), $shares));
	}

	/**
	 * Public endpoint: returns the owner's current position for a share link.
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function position(string $token): DataResponse {
		try {
			$share = $this->shareMapper->findByToken($token);
		} catch (DoesNotExistException $e) {
			return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		if ($share->getExpiresAt() !== null && $share->getExpiresAt() < time()) {
			return new DataResponse(['error' => 'expired'], Http::STATUS_GONE);
		}

		try {
			$pos = $this->positionMapper->findByUserId($share->getOwnerUserId());
			return new DataResponse([
				'lat' => $pos->getLat(),
				'lon' => $pos->getLon(),
				'acc' => $pos->getAcc(),
				'updatedAt' => $pos->getUpdatedAt(),
				'hasPosition' => true,
				'expiresAt' => $share->getExpiresAt(),
			]);
		} catch (DoesNotExistException $e) {
			return new DataResponse([
				'hasPosition' => false,
				'expiresAt' => $share->getExpiresAt(),
			]);
		}
	}

	private function formatShare(Share $share): array {
		$viewUrl = rtrim($this->urlGenerator->getAbsoluteURL('/'), '/')
			. $this->urlGenerator->linkToRoute('wepwawet.page.view', ['token' => $share->getToken()]);

		return [
			'id' => $share->getId(),
			'token' => $share->getToken(),
			'url' => $viewUrl,
			'expiresAt' => $share->getExpiresAt(),
			'createdAt' => $share->getCreatedAt(),
		];
	}
}

<?php

namespace OCA\Sopdet\Controller;

use OCA\Sopdet\Db\Guest;
use OCA\Sopdet\Db\GuestMapper;
use OCA\Sopdet\Db\GroupMapper;
use OCA\Sopdet\Db\Position;
use OCA\Sopdet\Db\PositionMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class PositionController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private PositionMapper $positionMapper,
		private GuestMapper $guestMapper,
		private GroupMapper $groupMapper,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Authenticated user updates their position.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function update(
		float $lat, float $lon,
		?float $acc = null, ?float $alt = null,
		?float $speed = null, ?float $bearing = null,
	): DataResponse {
		$position = new Position();
		$position->setUserId($this->userId);
		$position->setLat($lat);
		$position->setLon($lon);
		$position->setAcc($acc);
		$position->setAlt($alt);
		$position->setSpeed($speed);
		$position->setBearing($bearing);
		$position->setUpdatedAt(time());

		$this->positionMapper->upsert($position);

		return new DataResponse(['ok' => true]);
	}

	/**
	 * Guest (unauthenticated) updates their position using the group invite token.
	 * The name param identifies the guest within the group.
	 * Optional duration param (in minutes) sets when the guest expires.
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function guestUpdate(
		string $token, string $name,
		?float $lat = null, ?float $lon = null,
		?float $acc = null, ?float $alt = null,
		?float $speed = null, ?float $bearing = null,
		?int $duration = null,
		bool $stop = false,
	): DataResponse {
		try {
			$group = $this->groupMapper->findByToken($token);
		} catch (DoesNotExistException $e) {
			return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		$cleanName = mb_substr(trim($name), 0, 64);

		if ($stop) {
			$this->guestMapper->deleteByGroupAndName($group->getId(), $cleanName);
			return new DataResponse(['ok' => true]);
		}

		if ($lat === null || $lon === null) {
			return new DataResponse(['error' => 'lat and lon required'], Http::STATUS_BAD_REQUEST);
		}

		$guest = new Guest();
		$guest->setGroupId($group->getId());
		$guest->setName($cleanName);
		$guest->setLat($lat);
		$guest->setLon($lon);
		$guest->setAcc($acc);
		$guest->setAlt($alt);
		$guest->setSpeed($speed);
		$guest->setBearing($bearing);
		$guest->setUpdatedAt(time());

		if ($duration !== null && $duration > 0) {
			$guest->setExpiresAt(time() + $duration * 60);
		}

		$this->guestMapper->upsert($guest);

		return new DataResponse(['ok' => true]);
	}
}

<?php

namespace OCA\LocShare\Controller;

use OCA\LocShare\Db\GroupMapper;
use OCA\LocShare\Db\GroupMember;
use OCA\LocShare\Db\GroupMemberMapper;
use OCA\LocShare\Db\GuestMapper;
use OCA\LocShare\Db\PositionMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;

class GroupController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private GroupMapper $groupMapper,
		private GroupMemberMapper $groupMemberMapper,
		private PositionMapper $positionMapper,
		private GuestMapper $guestMapper,
		private IUserManager $userManager,
		private IURLGenerator $urlGenerator,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Returns the current user's group info for the companion app.
	 * Finds the group without auto-creating one (the web app does that on first login).
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function me(): DataResponse {
		$owned = $this->groupMapper->findByOwner($this->userId);
		if (count($owned) > 0) {
			$group = $owned[0];
		} else {
			$group = null;
			foreach ($this->groupMemberMapper->findByUser($this->userId) as $membership) {
				try {
					$group = $this->groupMapper->find($membership->getGroupId());
					break;
				} catch (DoesNotExistException $e) {
					continue;
				}
			}
		}

		if ($group === null) {
			return new DataResponse(
				['error' => 'no_group', 'message' => 'Open the LocShare web app first to create your group.'],
				Http::STATUS_NOT_FOUND,
			);
		}

		return new DataResponse([
			'groupId'      => $group->getId(),
			'groupToken'   => $group->getToken(),
			'inviteUrl'    => rtrim($this->urlGenerator->getAbsoluteURL('/'), '/')
				. $this->urlGenerator->linkToRoute('locshare.page.join', ['token' => $group->getToken()]),
			'positionsUrl' => $this->urlGenerator->linkToRoute('locshare.group.positions', ['id' => $group->getId()]),
			'updateUrl'    => $this->urlGenerator->linkToRoute('locshare.position.update'),
		]);
	}

	/**
	 * Logged-in user accepts an invite link and joins the group.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function accept(string $token): DataResponse {
		try {
			$group = $this->groupMapper->findByToken($token);
		} catch (DoesNotExistException $e) {
			return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		if (!$this->groupMemberMapper->isMember($group->getId(), $this->userId)) {
			$member = new GroupMember();
			$member->setGroupId($group->getId());
			$member->setUserId($this->userId);
			$member->setJoinedAt(time());
			$this->groupMemberMapper->insert($member);
		}

		return new DataResponse([
			'main_url' => $this->urlGenerator->linkToRoute('locshare.page.index'),
		]);
	}

	/**
	 * Returns all members and their current positions for a group.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function positions(int $id): DataResponse {
		if (!$this->groupMemberMapper->isMember($id, $this->userId)) {
			return new DataResponse(['error' => 'not_a_member'], Http::STATUS_FORBIDDEN);
		}

		$members = $this->groupMemberMapper->findByGroup($id);
		$userIds = array_map(fn (GroupMember $m) => $m->getUserId(), $members);

		$positions = $this->positionMapper->findByUserIds($userIds);
		$positionMap = [];
		foreach ($positions as $pos) {
			$positionMap[$pos->getUserId()] = $pos;
		}

		$result = [];
		foreach ($members as $member) {
			$uid = $member->getUserId();
			$user = $this->userManager->get($uid);
			$pos = $positionMap[$uid] ?? null;

			$result[] = [
				'type' => 'user',
				'userId' => $uid,
				'displayName' => $user?->getDisplayName() ?? $uid,
				'avatarUrl' => '/index.php/avatar/' . urlencode($uid) . '/64',
				'isMe' => $uid === $this->userId,
				'lat' => $pos?->getLat(),
				'lon' => $pos?->getLon(),
				'acc' => $pos?->getAcc(),
				'updatedAt' => $pos?->getUpdatedAt(),
				'hasPosition' => $pos !== null,
			];
		}

		// Active guests
		foreach ($this->guestMapper->findActiveByGroup($id) as $guest) {
			if ($guest->getLat() === null) {
				continue;
			}
			$result[] = [
				'type' => 'guest',
				'userId' => 'guest_' . $guest->getId(),
				'displayName' => $guest->getName(),
				'avatarUrl' => null,
				'isMe' => false,
				'lat' => $guest->getLat(),
				'lon' => $guest->getLon(),
				'acc' => $guest->getAcc(),
				'updatedAt' => $guest->getUpdatedAt(),
				'hasPosition' => true,
			];
		}

		return new DataResponse($result);
	}
}

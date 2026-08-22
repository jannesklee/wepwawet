<?php

namespace OCA\LocShare\Controller;

use OCA\LocShare\Db\Group;
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
	 * Returns every group the current user owns or has joined, for the
	 * companion app. The web app fetches the same list client-side.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function list(): DataResponse {
		$groups = [];
		$seenIds = [];

		foreach ($this->groupMapper->findByOwner($this->userId) as $group) {
			$groups[] = $group;
			$seenIds[$group->getId()] = true;
		}
		foreach ($this->groupMemberMapper->findByUser($this->userId) as $membership) {
			if (isset($seenIds[$membership->getGroupId()])) {
				continue;
			}
			try {
				$groups[] = $this->groupMapper->find($membership->getGroupId());
				$seenIds[$membership->getGroupId()] = true;
			} catch (DoesNotExistException $e) {
				continue;
			}
		}

		return new DataResponse([
			'updateUrl' => $this->urlGenerator->linkToRoute('locshare.position.update'),
			'groups' => array_map(fn ($group) => $this->formatGroup($group), $groups),
		]);
	}

	/**
	 * Creates a new named group owned by the current user.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function create(string $name = ''): DataResponse {
		$name = trim($name);
		if ($name === '') {
			return new DataResponse(['error' => 'name_required'], Http::STATUS_BAD_REQUEST);
		}

		$group = new Group();
		$group->setName($name);
		$group->setOwnerUserId($this->userId);
		$group->setToken(bin2hex(random_bytes(16)));
		$group->setCreatedAt(time());
		$group = $this->groupMapper->insert($group);

		$member = new GroupMember();
		$member->setGroupId($group->getId());
		$member->setUserId($this->userId);
		$member->setJoinedAt(time());
		$member->setVisible(true);
		$this->groupMemberMapper->insert($member);

		return new DataResponse($this->formatGroup($group));
	}

	/**
	 * Sets whether the current user's position is visible to a specific
	 * group. Does not affect any other group or share link they have.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function setVisibility(int $id, bool $visible = true): DataResponse {
		try {
			$member = $this->groupMemberMapper->findByGroupAndUser($id, $this->userId);
		} catch (DoesNotExistException $e) {
			return new DataResponse(['error' => 'not_a_member'], Http::STATUS_FORBIDDEN);
		}

		$member->setVisible($visible);
		$this->groupMemberMapper->update($member);

		return new DataResponse(['visible' => $visible]);
	}

	private function formatGroup(Group $group): array {
		$memberCount = count($this->groupMemberMapper->findByGroup($group->getId()));
		$visible = true;
		try {
			$visible = $this->groupMemberMapper->findByGroupAndUser($group->getId(), $this->userId)->getVisible();
		} catch (DoesNotExistException $e) {
			// Owner not yet in group_members (shouldn't happen, but don't fail the whole list)
		}

		return [
			'id'           => $group->getId(),
			'name'         => $group->getName(),
			'token'        => $group->getToken(),
			'isOwner'      => $group->getOwnerUserId() === $this->userId,
			'memberCount'  => $memberCount,
			'visible'      => $visible,
			'inviteUrl'    => rtrim($this->urlGenerator->getAbsoluteURL('/'), '/')
				. $this->urlGenerator->linkToRoute('locshare.page.join', ['token' => $group->getToken()]),
			'positionsUrl' => $this->urlGenerator->linkToRoute('locshare.group.positions', ['id' => $group->getId()]),
		];
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
			// A member can hide their position from this specific group without
			// affecting any other group or share link they're part of.
			$pos = $member->getVisible() ? ($positionMap[$uid] ?? null) : null;

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

<?php

namespace OCA\Wepwawet\Controller;

use OCA\Wepwawet\Db\Group;
use OCA\Wepwawet\Db\GroupMapper;
use OCA\Wepwawet\Db\GroupMember;
use OCA\Wepwawet\Db\GroupMemberMapper;
use OCA\Wepwawet\Db\GuestMapper;
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
			'updateUrl' => $this->urlGenerator->linkToRoute('wepwawet.position.update'),
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

	/**
	 * Removes another member from a group the current user owns. The owner
	 * can't remove themselves this way - use delete() to remove the whole
	 * group instead.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function removeMember(int $id, string $userId): DataResponse {
		try {
			$group = $this->groupMapper->find($id);
		} catch (DoesNotExistException $e) {
			return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		if ($group->getOwnerUserId() !== $this->userId) {
			return new DataResponse(['error' => 'not_owner'], Http::STATUS_FORBIDDEN);
		}
		if ($userId === $group->getOwnerUserId()) {
			return new DataResponse(['error' => 'cannot_remove_owner'], Http::STATUS_BAD_REQUEST);
		}

		$this->groupMemberMapper->deleteByGroupAndUser($id, $userId);

		return new DataResponse([]);
	}

	/**
	 * Deletes a group the current user owns, along with its memberships
	 * and any guest entries. Doesn't touch any share links (they aren't
	 * group-scoped).
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function delete(int $id): DataResponse {
		try {
			$group = $this->groupMapper->find($id);
		} catch (DoesNotExistException $e) {
			return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		if ($group->getOwnerUserId() !== $this->userId) {
			return new DataResponse(['error' => 'not_owner'], Http::STATUS_FORBIDDEN);
		}

		$this->groupMemberMapper->deleteByGroup($id);
		$this->guestMapper->deleteByGroup($id);
		$this->groupMapper->delete($group);

		return new DataResponse([]);
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
				. $this->urlGenerator->linkToRoute('wepwawet.page.join', ['token' => $group->getToken()]),
			'positionsUrl' => $this->urlGenerator->linkToRoute('wepwawet.group.positions', ['id' => $group->getId()]),
		];
	}

	/**
	 * Logged-in user accepts an invite link and joins the group.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	/**
	 * Public group name/owner lookup by invite token, for the companion app's
	 * guest mode - it never loads the server-rendered join page (that's how
	 * the web guest flow gets group_name/owner_display_name, see
	 * PageController::join), so it needs this to label a joined group with
	 * something better than the guest's own display name.
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function info(string $token): DataResponse {
		try {
			$group = $this->groupMapper->findByToken($token);
		} catch (DoesNotExistException $e) {
			return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		$owner = $this->userManager->get($group->getOwnerUserId());

		return new DataResponse([
			'name' => $group->getName(),
			'ownerDisplayName' => $owner?->getDisplayName() ?? $group->getOwnerUserId(),
		]);
	}

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
			'main_url' => $this->urlGenerator->linkToRoute('wepwawet.page.index'),
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

		return new DataResponse($this->buildPositions($id, $this->userId, null));
	}

	/**
	 * Same as positions(), but for an unauthenticated guest identified by the
	 * group's invite token instead of a Nextcloud session - the token is the
	 * same trust boundary already used by PositionController::guestUpdate().
	 * The name param (if it matches an active guest) marks that entry as "me".
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function guestPositions(string $token, string $name = ''): DataResponse {
		try {
			$group = $this->groupMapper->findByToken($token);
		} catch (DoesNotExistException $e) {
			return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		$cleanName = mb_substr(trim($name), 0, 64);

		return new DataResponse($this->buildPositions($group->getId(), null, $cleanName));
	}

	/**
	 * Shared by positions() and guestPositions(). Exactly one of $forUserId /
	 * $forGuestName should be non-empty - it's used only to flag which entry
	 * in the result is "me" for the caller.
	 */
	private function buildPositions(int $groupId, ?string $forUserId, ?string $forGuestName): array {
		$members = $this->groupMemberMapper->findByGroup($groupId);
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
				'isMe' => $forUserId !== null && $uid === $forUserId,
				'lat' => $pos?->getLat(),
				'lon' => $pos?->getLon(),
				'acc' => $pos?->getAcc(),
				'updatedAt' => $pos?->getUpdatedAt(),
				'hasPosition' => $pos !== null,
			];
		}

		// Active guests
		foreach ($this->guestMapper->findActiveByGroup($groupId) as $guest) {
			if ($guest->getLat() === null) {
				continue;
			}
			$result[] = [
				'type' => 'guest',
				'userId' => 'guest_' . $guest->getId(),
				'displayName' => $guest->getName(),
				'avatarUrl' => null,
				'isMe' => $forGuestName !== null && $forGuestName !== '' && $guest->getName() === $forGuestName,
				'lat' => $guest->getLat(),
				'lon' => $guest->getLon(),
				'acc' => $guest->getAcc(),
				'updatedAt' => $guest->getUpdatedAt(),
				'hasPosition' => true,
			];
		}

		return $result;
	}
}

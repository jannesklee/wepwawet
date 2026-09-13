<?php

namespace OCA\Sopdet\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int getId()
 * @method int getGroupId()
 * @method void setGroupId(int $groupId)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getJoinedAt()
 * @method void setJoinedAt(int $joinedAt)
 * @method bool getVisible()
 * @method void setVisible(bool $visible)
 */
class GroupMember extends Entity {

	protected $groupId;
	protected $userId;
	protected $joinedAt;
	protected $visible;

	public function __construct() {
		$this->addType('groupId', Types::INTEGER);
		$this->addType('userId', Types::STRING);
		$this->addType('joinedAt', Types::INTEGER);
		$this->addType('visible', Types::BOOLEAN);
	}
}

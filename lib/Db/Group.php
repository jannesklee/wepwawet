<?php

namespace OCA\LocShare\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int getId()
 * @method string getName()
 * @method void setName(string $name)
 * @method string getOwnerUserId()
 * @method void setOwnerUserId(string $ownerUserId)
 * @method string getToken()
 * @method void setToken(string $token)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class Group extends Entity {

	protected $name;
	protected $ownerUserId;
	protected $token;
	protected $createdAt;

	public function __construct() {
		$this->addType('name', Types::STRING);
		$this->addType('ownerUserId', Types::STRING);
		$this->addType('token', Types::STRING);
		$this->addType('createdAt', Types::INTEGER);
	}
}

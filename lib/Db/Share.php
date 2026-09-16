<?php

namespace OCA\Wepwawet\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int getId()
 * @method string getOwnerUserId()
 * @method void setOwnerUserId(string $ownerUserId)
 * @method string getToken()
 * @method void setToken(string $token)
 * @method int|null getExpiresAt()
 * @method void setExpiresAt(?int $expiresAt)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class Share extends Entity {

	protected $ownerUserId;
	protected $token;
	protected $expiresAt;
	protected $createdAt;

	public function __construct() {
		$this->addType('ownerUserId', Types::STRING);
		$this->addType('token', Types::STRING);
		$this->addType('expiresAt', Types::INTEGER);
		$this->addType('createdAt', Types::INTEGER);
	}
}

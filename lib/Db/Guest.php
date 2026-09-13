<?php

namespace OCA\Sopdet\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int getId()
 * @method int getGroupId()
 * @method void setGroupId(int $groupId)
 * @method string getName()
 * @method void setName(string $name)
 * @method float|null getLat()
 * @method void setLat(?float $lat)
 * @method float|null getLon()
 * @method void setLon(?float $lon)
 * @method float|null getAcc()
 * @method void setAcc(?float $acc)
 * @method float|null getAlt()
 * @method void setAlt(?float $alt)
 * @method float|null getSpeed()
 * @method void setSpeed(?float $speed)
 * @method float|null getBearing()
 * @method void setBearing(?float $bearing)
 * @method int|null getUpdatedAt()
 * @method void setUpdatedAt(?int $updatedAt)
 * @method int|null getExpiresAt()
 * @method void setExpiresAt(?int $expiresAt)
 */
class Guest extends Entity {

	protected $groupId;
	protected $name;
	protected $lat;
	protected $lon;
	protected $acc;
	protected $alt;
	protected $speed;
	protected $bearing;
	protected $updatedAt;
	protected $expiresAt;

	public function __construct() {
		$this->addType('groupId', Types::INTEGER);
		$this->addType('name', Types::STRING);
		$this->addType('lat', Types::FLOAT);
		$this->addType('lon', Types::FLOAT);
		$this->addType('acc', Types::FLOAT);
		$this->addType('alt', Types::FLOAT);
		$this->addType('speed', Types::FLOAT);
		$this->addType('bearing', Types::FLOAT);
		$this->addType('updatedAt', Types::INTEGER);
		$this->addType('expiresAt', Types::INTEGER);
	}
}

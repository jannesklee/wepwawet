<?php

namespace OCA\Sopdet\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int getId()
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method float getLat()
 * @method void setLat(float $lat)
 * @method float getLon()
 * @method void setLon(float $lon)
 * @method float|null getAcc()
 * @method void setAcc(?float $acc)
 * @method float|null getAlt()
 * @method void setAlt(?float $alt)
 * @method float|null getSpeed()
 * @method void setSpeed(?float $speed)
 * @method float|null getBearing()
 * @method void setBearing(?float $bearing)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class Position extends Entity {

	protected $userId;
	protected $lat;
	protected $lon;
	protected $acc;
	protected $alt;
	protected $speed;
	protected $bearing;
	protected $updatedAt;

	public function __construct() {
		$this->addType('userId', Types::STRING);
		$this->addType('lat', Types::FLOAT);
		$this->addType('lon', Types::FLOAT);
		$this->addType('acc', Types::FLOAT);
		$this->addType('alt', Types::FLOAT);
		$this->addType('speed', Types::FLOAT);
		$this->addType('bearing', Types::FLOAT);
		$this->addType('updatedAt', Types::INTEGER);
	}
}

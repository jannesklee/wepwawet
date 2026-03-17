<?php

namespace OCA\LocShare\BackgroundJob;

use OCA\LocShare\Db\GuestMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

class CleanupExpiredGuests extends TimedJob {

	public function __construct(
		ITimeFactory $time,
		private GuestMapper $guestMapper,
	) {
		parent::__construct($time);
		// Run once per hour
		$this->setInterval(3600);
	}

	protected function run(mixed $argument): void {
		$this->guestMapper->deleteExpired();
	}
}

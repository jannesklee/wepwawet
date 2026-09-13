<?php

namespace OCA\Sopdet\BackgroundJob;

use OCA\Sopdet\Db\GuestMapper;
use OCA\Sopdet\Db\ShareMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

class CleanupExpiredGuests extends TimedJob {

	public function __construct(
		ITimeFactory $time,
		private GuestMapper $guestMapper,
		private ShareMapper $shareMapper,
	) {
		parent::__construct($time);
		// Run once per hour
		$this->setInterval(3600);
	}

	protected function run(mixed $argument): void {
		$this->guestMapper->deleteExpired();
		$this->shareMapper->deleteExpired();
	}
}

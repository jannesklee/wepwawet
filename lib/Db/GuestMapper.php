<?php

namespace OCA\Wepwawet\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Guest>
 */
class GuestMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'wepwawet_guests', Guest::class);
	}

	/** @return Guest[] */
	public function findActiveByGroup(int $groupId): array {
		$now = time();
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('group_id', $qb->createNamedParameter($groupId, IQueryBuilder::PARAM_INT)))
			->andWhere(
				$qb->expr()->orX(
					$qb->expr()->isNull('expires_at'),
					$qb->expr()->gt('expires_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				)
			);
		return $this->findEntities($qb);
	}

	/** @throws DoesNotExistException */
	public function findByGroupAndName(int $groupId, string $name): Guest {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('group_id', $qb->createNamedParameter($groupId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('name', $qb->createNamedParameter($name)));
		return $this->findEntity($qb);
	}

	public function deleteByGroupAndName(int $groupId, string $name): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('group_id', $qb->createNamedParameter($groupId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('name', $qb->createNamedParameter($name)));
		$qb->executeStatement();
	}

	public function deleteByGroup(int $groupId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('group_id', $qb->createNamedParameter($groupId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	public function deleteExpired(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->isNotNull('expires_at'))
			->andWhere($qb->expr()->lte('expires_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}

	public function upsert(Guest $guest): void {
		try {
			$existing = $this->findByGroupAndName($guest->getGroupId(), $guest->getName());
			$this->applyUpdate($existing, $guest);
			return;
		} catch (DoesNotExistException $e) {
			// No row yet - fall through to insert below.
		}

		try {
			$this->insert($guest);
		} catch (DbException $e) {
			// Two position updates for the same (group_id, name) can race on a
			// guest's very first fix (e.g. the browser's watchPosition firing
			// twice in quick succession before either request's insert lands):
			// both see no existing row and both try to insert. The loser hits
			// the unique constraint - by now the winner's row exists, so retry
			// as an update instead of surfacing a 500 to the guest.
			if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			$existing = $this->findByGroupAndName($guest->getGroupId(), $guest->getName());
			$this->applyUpdate($existing, $guest);
		}
	}

	private function applyUpdate(Guest $existing, Guest $incoming): void {
		$existing->setLat($incoming->getLat());
		$existing->setLon($incoming->getLon());
		$existing->setAcc($incoming->getAcc());
		$existing->setAlt($incoming->getAlt());
		$existing->setSpeed($incoming->getSpeed());
		$existing->setBearing($incoming->getBearing());
		$existing->setUpdatedAt($incoming->getUpdatedAt());
		if ($incoming->getExpiresAt() !== null) {
			$existing->setExpiresAt($incoming->getExpiresAt());
		}
		$this->update($existing);
	}
}

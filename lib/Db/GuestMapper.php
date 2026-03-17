<?php

namespace OCA\LocShare\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Guest>
 */
class GuestMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'locshare_guests', Guest::class);
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
			$existing->setLat($guest->getLat());
			$existing->setLon($guest->getLon());
			$existing->setAcc($guest->getAcc());
			$existing->setAlt($guest->getAlt());
			$existing->setSpeed($guest->getSpeed());
			$existing->setBearing($guest->getBearing());
			$existing->setUpdatedAt($guest->getUpdatedAt());
			if ($guest->getExpiresAt() !== null) {
				$existing->setExpiresAt($guest->getExpiresAt());
			}
			$this->update($existing);
		} catch (DoesNotExistException $e) {
			$this->insert($guest);
		}
	}
}

<?php

namespace OCA\LocShare\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Position>
 */
class PositionMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'locshare_positions', Position::class);
	}

	/** @throws DoesNotExistException */
	public function findByUserId(string $userId): Position {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $this->findEntity($qb);
	}

	/**
	 * @param string[] $userIds
	 * @return Position[]
	 */
	public function findByUserIds(array $userIds): array {
		if (empty($userIds)) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('user_id', $qb->createNamedParameter($userIds, IQueryBuilder::PARAM_STR_ARRAY)));
		return $this->findEntities($qb);
	}

	public function upsert(Position $position): void {
		try {
			$existing = $this->findByUserId($position->getUserId());
			$existing->setLat($position->getLat());
			$existing->setLon($position->getLon());
			$existing->setAcc($position->getAcc());
			$existing->setAlt($position->getAlt());
			$existing->setSpeed($position->getSpeed());
			$existing->setBearing($position->getBearing());
			$existing->setUpdatedAt($position->getUpdatedAt());
			$this->update($existing);
		} catch (DoesNotExistException $e) {
			$this->insert($position);
		}
	}
}

<?php

namespace OCA\Sopdet\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Share>
 */
class ShareMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'sopdet_shares', Share::class);
	}

	/** @throws DoesNotExistException */
	public function find(int $id): Share {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/** @throws DoesNotExistException */
	public function findByToken(string $token): Share {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token)));
		return $this->findEntity($qb);
	}

	/** @return Share[] */
	public function findActiveByUser(string $userId): array {
		$now = time();
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('owner_user_id', $qb->createNamedParameter($userId)))
			->andWhere(
				$qb->expr()->orX(
					$qb->expr()->isNull('expires_at'),
					$qb->expr()->gt('expires_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				)
			)
			->orderBy('created_at', 'DESC');
		return $this->findEntities($qb);
	}

	public function deleteExpired(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->isNotNull('expires_at'))
			->andWhere($qb->expr()->lte('expires_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}
}

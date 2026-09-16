<?php

namespace OCA\Wepwawet\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000000Date20260317000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('wepwawet_shares')) {
			$table = $schema->createTable('wepwawet_shares');
			$table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('owner_user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('token', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('expires_at', Types::INTEGER, ['notnull' => false]);
			$table->addColumn('created_at', Types::INTEGER, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['token'], 'wepwawet_shares_token');
			$table->addIndex(['owner_user_id'], 'wepwawet_shares_owner');
		}

		return $schema;
	}
}

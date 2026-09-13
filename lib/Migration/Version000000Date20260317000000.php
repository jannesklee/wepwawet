<?php

namespace OCA\Sopdet\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000000Date20260317000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('sopdet_shares')) {
			$table = $schema->createTable('sopdet_shares');
			$table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('owner_user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('token', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('expires_at', Types::INTEGER, ['notnull' => false]);
			$table->addColumn('created_at', Types::INTEGER, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['token'], 'sopdet_shares_token');
			$table->addIndex(['owner_user_id'], 'sopdet_shares_owner');
		}

		return $schema;
	}
}

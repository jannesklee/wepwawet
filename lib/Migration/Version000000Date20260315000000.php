<?php

namespace OCA\LocShare\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000000Date20260315000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('locshare_groups')) {
			$table = $schema->createTable('locshare_groups');
			$table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('name', Types::STRING, ['length' => 255, 'notnull' => true]);
			$table->addColumn('owner_user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('token', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('created_at', Types::INTEGER, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['token'], 'locshare_groups_token');
			$table->addIndex(['owner_user_id'], 'locshare_groups_owner');
		}

		if (!$schema->hasTable('locshare_group_members')) {
			$table = $schema->createTable('locshare_group_members');
			$table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('group_id', Types::INTEGER, ['notnull' => true]);
			$table->addColumn('user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('joined_at', Types::INTEGER, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['group_id', 'user_id'], 'locshare_gm_unique');
			$table->addIndex(['user_id'], 'locshare_gm_user');
		}

		if (!$schema->hasTable('locshare_positions')) {
			$table = $schema->createTable('locshare_positions');
			$table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('lat', Types::FLOAT, ['notnull' => true]);
			$table->addColumn('lon', Types::FLOAT, ['notnull' => true]);
			$table->addColumn('acc', Types::FLOAT, ['notnull' => false]);
			$table->addColumn('alt', Types::FLOAT, ['notnull' => false]);
			$table->addColumn('speed', Types::FLOAT, ['notnull' => false]);
			$table->addColumn('bearing', Types::FLOAT, ['notnull' => false]);
			$table->addColumn('updated_at', Types::INTEGER, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['user_id'], 'locshare_pos_user');
		}

		if (!$schema->hasTable('locshare_guests')) {
			$table = $schema->createTable('locshare_guests');
			$table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('group_id', Types::INTEGER, ['notnull' => true]);
			$table->addColumn('name', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('lat', Types::FLOAT, ['notnull' => false]);
			$table->addColumn('lon', Types::FLOAT, ['notnull' => false]);
			$table->addColumn('acc', Types::FLOAT, ['notnull' => false]);
			$table->addColumn('alt', Types::FLOAT, ['notnull' => false]);
			$table->addColumn('speed', Types::FLOAT, ['notnull' => false]);
			$table->addColumn('bearing', Types::FLOAT, ['notnull' => false]);
			$table->addColumn('updated_at', Types::INTEGER, ['notnull' => false]);
			$table->addColumn('expires_at', Types::INTEGER, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['group_id'], 'locshare_guests_group');
			$table->addUniqueIndex(['group_id', 'name'], 'locshare_guests_name');
		}

		return $schema;
	}
}

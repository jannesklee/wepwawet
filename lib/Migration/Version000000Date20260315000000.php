<?php

namespace OCA\Wepwawet\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000000Date20260315000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('wepwawet_groups')) {
			$table = $schema->createTable('wepwawet_groups');
			$table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('name', Types::STRING, ['length' => 255, 'notnull' => true]);
			$table->addColumn('owner_user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('token', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('created_at', Types::INTEGER, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['token'], 'wepwawet_groups_token');
			$table->addIndex(['owner_user_id'], 'wepwawet_groups_owner');
		}

		if (!$schema->hasTable('wepwawet_group_members')) {
			$table = $schema->createTable('wepwawet_group_members');
			$table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('group_id', Types::INTEGER, ['notnull' => true]);
			$table->addColumn('user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('joined_at', Types::INTEGER, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['group_id', 'user_id'], 'wepwawet_gm_unique');
			$table->addIndex(['user_id'], 'wepwawet_gm_user');
		}

		if (!$schema->hasTable('wepwawet_positions')) {
			$table = $schema->createTable('wepwawet_positions');
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
			$table->addUniqueIndex(['user_id'], 'wepwawet_pos_user');
		}

		if (!$schema->hasTable('wepwawet_guests')) {
			$table = $schema->createTable('wepwawet_guests');
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
			$table->addIndex(['group_id'], 'wepwawet_guests_group');
			$table->addUniqueIndex(['group_id', 'name'], 'wepwawet_guests_name');
		}

		return $schema;
	}
}

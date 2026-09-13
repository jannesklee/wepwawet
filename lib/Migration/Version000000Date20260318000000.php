<?php

namespace OCA\Sopdet\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000000Date20260318000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$table = $schema->getTable('sopdet_group_members');
		if (!$table->hasColumn('visible')) {
			$table->addColumn('visible', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
		}

		return $schema;
	}
}

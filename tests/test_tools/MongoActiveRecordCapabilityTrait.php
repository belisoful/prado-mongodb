<?php

use Prado\Data\ActiveRecord\Relations\TActiveRecordHasManyAssociation;
use Prado\Data\ActiveRecord\Relations\TActiveRecordRelation;
use Prado\Data\ActiveRecord\TActiveRecord;

/**
 * MongoActiveRecordCapabilityTrait skips ActiveRecord-on-MongoDB tests that need
 * schemaless support in the ActiveRecord package itself, which a released
 * PRADO / prado-activerecord version may not have.  Each check detects the
 * framework capability (a method), never a version number, and names exactly
 * what is missing.
 */
trait MongoActiveRecordCapabilityTrait
{
	/**
	 * Skips the test when the ActiveRecord package is absent.  PRADO 4.3.3
	 * bundles it; PRADO 4.4 ships it as the optional pradosoft/prado-activerecord.
	 */
	protected function requireActiveRecord(): void
	{
		if (!class_exists(TActiveRecord::class)) {
			$this->markTestSkipped('ActiveRecord is not installed: on PRADO 4.4 it is the optional package pradosoft/prado-activerecord.');
		}
	}

	/**
	 * Skips the test when the record gateway cannot persist the fields of a
	 * record on a schemaless store.  Without it the gateway writes only the
	 * fields the store's metadata lists (just `_id` on an empty collection).
	 */
	protected function requireSchemalessRecordFields(): void
	{
		$this->requireActiveRecord();
		if (!method_exists(TActiveRecord::class, 'getRecordColumnValues')) {
			$this->markTestSkipped('Requires ActiveRecord schemaless field persistence (TActiveRecord::getRecordColumnValues() and '
				. 'the IDataSchemalessTableInfo branch of TActiveRecordGateway::getInsertValues()/getUpdateValues()), '
				. 'which this ActiveRecord version does not have.');
		}
	}

	/**
	 * Skips the test when ActiveRecord relations cannot derive their keys from the
	 * declared foreign-key field on a store with no foreign-key metadata.
	 */
	protected function requireSchemalessRelations(): void
	{
		$this->requireSchemalessRecordFields();
		if (!method_exists(TActiveRecordRelation::class, 'synthesizeForeignKeys')
			&& !method_exists(TActiveRecordRelation::class, 'buildDeclaredForeignKeys')) {
			$this->markTestSkipped('Requires ActiveRecord relations on stores without foreign-key metadata '
				. '(TActiveRecordRelation::synthesizeForeignKeys() on PRADO 4.3.3, '
				. 'TActiveRecordRelation::buildDeclaredForeignKeys() in prado-activerecord), '
				. 'which this ActiveRecord version does not have.');
		}
	}

	/**
	 * Skips the test when MANY_TO_MANY relations cannot resolve through a
	 * schemaless association collection.
	 */
	protected function requireSchemalessManyToMany(): void
	{
		$this->requireSchemalessRelations();
		if (!method_exists(TActiveRecordHasManyAssociation::class, 'collectForeignObjectsViaAssociation')
			&& !method_exists(TActiveRecordHasManyAssociation::class, 'getIsSchemalessAssociation')) {
			$this->markTestSkipped('Requires MANY_TO_MANY through a schemaless association collection '
				. '(TActiveRecordHasManyAssociation::collectForeignObjectsViaAssociation() on PRADO 4.3.3, '
				. 'TActiveRecordHasManyAssociation::getIsSchemalessAssociation() in prado-activerecord), '
				. 'which this ActiveRecord version does not have.');
		}
	}
}

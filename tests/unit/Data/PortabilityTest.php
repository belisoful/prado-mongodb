<?php

/**
 * PortabilityTest — proves drop-in compatibility with the Prado Data layer.
 *
 * Verifies that prado-mongodb's classes satisfy every contract on the
 * driver-agnostic IData* portability roadmap documented on
 * {@see \Prado\Data\IDataConnection}, so an application using DataGateway or
 * ActiveRecord can swap a TMongoConnection in for a TDbConnection without
 * code changes (modulo using TMongoQueryCriteria for non-trivial filters).
 *
 * No live MongoDB instance is required; all tests construct value objects
 * and command builders and assert against their structure.
 */

use Prado\Data\Common\IDataCommandBuilder;
use Prado\Data\Common\IDataColumn;
use Prado\Data\Common\IDataMetaData;
use Prado\Data\Common\IDataTableInfo;
use Prado\Data\Common\Mongo\TMongoCollectionInfo;
use Prado\Data\Common\Mongo\TMongoCommandBuilder;
use Prado\Data\Common\Mongo\TMongoFieldInfo;
use Prado\Data\Common\Mongo\TMongoMetaData;
use Prado\Data\DataGateway\IDataCriteria;
use Prado\Data\DataGateway\TMongoQueryCriteria;
use Prado\Data\IDataCommand;
use Prado\Data\IDataConnection;
use Prado\Data\IDataReader;
use Prado\Data\IDataTransaction;
use Prado\Data\TDataTransaction;
use Prado\Data\TMongoCommand;
use Prado\Data\TMongoConnection;
use Prado\Data\TMongoDataReader;
use Prado\Data\TMongoTransaction;

class PortabilityTest extends PHPUnit\Framework\TestCase
{
	protected function setUp(): void
	{
		if (!extension_loaded('mongodb')) {
			$this->markTestSkipped('The mongodb extension is not available.');
		}
	}

	// -----------------------------------------------------------------------
	// Required-interface compliance
	// -----------------------------------------------------------------------

	public function test_connection_implements_IDataConnection(): void
	{
		$conn = new TMongoConnection('mongodb://localhost:27017', '', '', 'prado_unitest');
		self::assertInstanceOf(IDataConnection::class, $conn);
	}

	public function test_command_implements_IDataCommand(): void
	{
		$conn = new TMongoConnection('mongodb://localhost:27017', '', '', 'prado_unitest');
		$cmd = new TMongoCommand($conn, 'collection');
		self::assertInstanceOf(IDataCommand::class, $cmd);
	}

	public function test_reader_implements_IDataReader(): void
	{
		self::assertContains(IDataReader::class, class_implements(TMongoDataReader::class));
	}

	public function test_transaction_extends_TDataTransaction(): void
	{
		self::assertTrue(is_subclass_of(TMongoTransaction::class, TDataTransaction::class));
		self::assertContains(IDataTransaction::class, class_implements(TMongoTransaction::class));
	}

	public function test_metadata_implements_IDataMetaData(): void
	{
		self::assertContains(IDataMetaData::class, class_implements(TMongoMetaData::class));
	}

	public function test_collection_info_implements_IDataTableInfo(): void
	{
		$info = new TMongoCollectionInfo('users');
		self::assertInstanceOf(IDataTableInfo::class, $info);
	}

	public function test_field_info_implements_IDataColumn(): void
	{
		$field = new TMongoFieldInfo(['FieldName' => 'name', 'BsonType' => 'string']);
		self::assertInstanceOf(IDataColumn::class, $field);
	}

	public function test_command_builder_implements_IDataCommandBuilder(): void
	{
		$conn = new TMongoConnection('mongodb://localhost:27017', '', '', 'prado_unitest');
		$info = new TMongoCollectionInfo('users');
		$builder = new TMongoCommandBuilder($conn, $info);
		self::assertInstanceOf(IDataCommandBuilder::class, $builder);
	}

	public function test_criteria_implements_IDataCriteria(): void
	{
		$criteria = new TMongoQueryCriteria(['status' => 'active']);
		self::assertInstanceOf(IDataCriteria::class, $criteria);
	}

	// -----------------------------------------------------------------------
	// PARAM_* constants on IDataConnection — runtime polymorphism
	// -----------------------------------------------------------------------

	public function test_connection_exposes_param_constants(): void
	{
		$conn = new TMongoConnection('mongodb://localhost:27017', '', '', 'prado_unitest');
		// Reference via instance — runtime class's constants must be visible.
		self::assertIsInt($conn::PARAM_NULL);
		self::assertIsInt($conn::PARAM_INT);
		self::assertIsInt($conn::PARAM_STR);
		self::assertIsInt($conn::PARAM_LOB);
		self::assertIsInt($conn::PARAM_BOOL);
	}

	// -----------------------------------------------------------------------
	// IDataCriteria — portable value-object surface
	// -----------------------------------------------------------------------

	public function test_criteria_exposes_portable_fields(): void
	{
		$criteria = new TMongoQueryCriteria(['x' => 1]);
		$criteria->getOrdersBy()->add('created_at', 'desc');
		$criteria->setLimit(10);
		$criteria->setOffset(20);
		$criteria->setSelect('name age');

		self::assertSame(['x' => 1], $criteria->getFilter());
		self::assertSame(['created_at' => 'desc'], $criteria->getOrdersBy()->toArray());
		self::assertSame(10, $criteria->getLimit());
		self::assertSame(20, $criteria->getOffset());
		self::assertSame('name age', $criteria->getSelect());
	}

	// -----------------------------------------------------------------------
	// IDataCommandBuilder — portable factory methods
	// -----------------------------------------------------------------------

	public function test_createPrimaryKeyCriteria_returns_id_filter_from_scalar(): void
	{
		$builder = $this->makeBuilder();
		$criteria = $builder->createPrimaryKeyCriteria(42);
		self::assertInstanceOf(IDataCriteria::class, $criteria);
		self::assertInstanceOf(TMongoQueryCriteria::class, $criteria);
		self::assertSame(['_id' => 42], $criteria->getFilter());
	}

	public function test_createPrimaryKeyCriteria_returns_id_filter_from_array(): void
	{
		$builder = $this->makeBuilder();
		$criteria = $builder->createPrimaryKeyCriteria(['_id' => 99]);
		self::assertInstanceOf(TMongoQueryCriteria::class, $criteria);
		self::assertSame(['_id' => 99], $criteria->getFilter());
	}

	public function test_createIndexCriteria_single_field_emits_in(): void
	{
		$builder = $this->makeBuilder();
		$criteria = $builder->createIndexCriteria(['author_id'], [[1], [2], [3]]);
		self::assertInstanceOf(TMongoQueryCriteria::class, $criteria);
		self::assertSame(['author_id' => ['$in' => [1, 2, 3]]], $criteria->getFilter());
	}

	public function test_createIndexCriteria_multi_field_emits_or(): void
	{
		$builder = $this->makeBuilder();
		$criteria = $builder->createIndexCriteria(
			['tenant_id', 'user_id'],
			[[1, 100], [1, 200], [2, 300]]
		);
		self::assertInstanceOf(TMongoQueryCriteria::class, $criteria);
		$filter = $criteria->getFilter();
		self::assertArrayHasKey('$or', $filter);
		self::assertCount(3, $filter['$or']);
		self::assertSame(['tenant_id' => 1, 'user_id' => 100], $filter['$or'][0]);
		self::assertSame(['tenant_id' => 2, 'user_id' => 300], $filter['$or'][2]);
	}

	public function test_createIndexCriteria_empty_values_matches_nothing(): void
	{
		$builder = $this->makeBuilder();
		$criteria = $builder->createIndexCriteria(['x'], []);
		self::assertInstanceOf(TMongoQueryCriteria::class, $criteria);
		$filter = $criteria->getFilter();
		// Must not match anything — uses an impossible _id condition.
		self::assertSame(['_id' => ['$exists' => false, '$type' => 'null']], $filter);
	}

	public function test_createFindCommandFromCriteria_returns_IDataCommand(): void
	{
		$builder = $this->makeBuilder();
		$criteria = new TMongoQueryCriteria(['status' => 'active']);
		$cmd = $builder->createFindCommandFromCriteria($criteria);
		self::assertInstanceOf(IDataCommand::class, $cmd);
	}

	public function test_createCountCommandFromCriteria_returns_IDataCommand(): void
	{
		$builder = $this->makeBuilder();
		$criteria = new TMongoQueryCriteria(['x' => 1]);
		$cmd = $builder->createCountCommandFromCriteria($criteria);
		self::assertInstanceOf(IDataCommand::class, $cmd);
	}

	public function test_createUpdateCommandFromCriteria_returns_IDataCommand(): void
	{
		$builder = $this->makeBuilder();
		$criteria = new TMongoQueryCriteria(['_id' => 1]);
		$cmd = $builder->createUpdateCommandFromCriteria(['name' => 'X'], $criteria);
		self::assertInstanceOf(IDataCommand::class, $cmd);
	}

	public function test_createDeleteCommandFromCriteria_returns_IDataCommand(): void
	{
		$builder = $this->makeBuilder();
		$criteria = new TMongoQueryCriteria(['_id' => 1]);
		$cmd = $builder->createDeleteCommandFromCriteria($criteria);
		self::assertInstanceOf(IDataCommand::class, $cmd);
	}

	public function test_createIndexedFindCommand_returns_IDataCommand(): void
	{
		$builder = $this->makeBuilder();
		$base = new TMongoQueryCriteria(['active' => true]);
		$cmd = $builder->createIndexedFindCommand($base, ['author_id'], [[1], [2]]);
		self::assertInstanceOf(IDataCommand::class, $cmd);
	}

	// -----------------------------------------------------------------------
	// Polymorphic consumer simulation — code typed against IData* must work
	// -----------------------------------------------------------------------

	public function test_portable_consumer_uses_only_IData_interfaces(): void
	{
		// Simulate what TDataGatewayCommand::findByPk does — typed entirely
		// against IData* contracts.  This proves a generic consumer can use
		// TMongoCommandBuilder as a drop-in for TDbCommandBuilder.
		$builder = $this->makeBuilder();
		$builderAsInterface = $this->assertReturnsIDataCommandBuilder($builder);
		$criteria = $builderAsInterface->createPrimaryKeyCriteria(7);
		$command = $builderAsInterface->createFindCommandFromCriteria($criteria);

		self::assertInstanceOf(IDataCriteria::class, $criteria);
		self::assertInstanceOf(IDataCommand::class, $command);
	}

	// -----------------------------------------------------------------------
	// IDb* discovery markers — Mongo MUST NOT implement them.
	// -----------------------------------------------------------------------

	public function test_mongo_does_NOT_implement_IDbConnection(): void
	{
		$conn = new TMongoConnection('mongodb://localhost:27017', '', '', 'prado_unitest');
		self::assertNotInstanceOf(\Prado\Data\IDbConnection::class, $conn);
	}

	public function test_mongo_does_NOT_implement_IDbCommand(): void
	{
		$conn = new TMongoConnection('mongodb://localhost:27017', '', '', 'prado_unitest');
		$cmd = new TMongoCommand($conn, 'collection');
		self::assertNotInstanceOf(\Prado\Data\IDbCommand::class, $cmd);
	}

	public function test_mongo_does_NOT_implement_IDbCommandBuilder(): void
	{
		$builder = $this->makeBuilder();
		self::assertNotInstanceOf(\Prado\Data\Common\IDbCommandBuilder::class, $builder);
	}

	public function test_mongo_does_NOT_implement_IDbMetaData(): void
	{
		self::assertNotContains(
			\Prado\Data\Common\IDbMetaData::class,
			class_implements(TMongoMetaData::class)
		);
	}

	public function test_mongo_field_does_NOT_implement_IDbColumn(): void
	{
		$field = new TMongoFieldInfo(['FieldName' => 'x', 'BsonType' => 'string']);
		self::assertNotInstanceOf(\Prado\Data\Common\IDbColumn::class, $field);
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	protected function makeBuilder(array $fields = []): TMongoCommandBuilder
	{
		$conn = new TMongoConnection('mongodb://localhost:27017', '', '', 'prado_unitest');
		$info = new TMongoCollectionInfo('users', $fields ?: [
			'_id'  => new TMongoFieldInfo(['FieldName' => '_id',  'BsonType' => 'objectId']),
			'name' => new TMongoFieldInfo(['FieldName' => 'name', 'BsonType' => 'string']),
		]);
		return new TMongoCommandBuilder($conn, $info);
	}

	/**
	 * Type-narrows to IDataCommandBuilder so the test body proves the
	 * mongo builder can be used through the abstract interface.
	 */
	protected function assertReturnsIDataCommandBuilder(IDataCommandBuilder $b): IDataCommandBuilder
	{
		return $b;
	}
}

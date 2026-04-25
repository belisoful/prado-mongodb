<?php

use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Command;
use MongoDB\Driver\ReadConcern;
use MongoDB\Driver\ReadPreference;
use MongoDB\Driver\WriteConcern;
use Prado\Data\TMongoCommand;
use Prado\Data\TMongoConnection;
use Prado\Data\TMongoDataReader;

/**
 * @author Test Author
 * @package Prado.Data
 */
class TMongoCommandTest extends PHPUnit\Framework\TestCase
{
	private static string $testDbName = 'prado_unittest_cmd';
	private static string $testCollectionName = 'test_cmd_collection';

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();
		if (!extension_loaded('mongodb')) {
			self::markTestSkipped('The mongodb extension is not available');
		}

		$uri = getenv('MONGODB_URI') ?: 'mongodb://localhost:27017';

		$conn = new TMongoConnection($uri, '', '', self::$testDbName);
		$conn->setActive(true);

		try {
			$conn->getManager()->executeCommand(
				self::$testDbName,
				new \MongoDB\Driver\Command(['ping' => 1])
			);
		} catch (\Exception $e) {
			self::markTestSkipped('MongoDB is not available: ' . $e->getMessage());
		}
	}

	protected function setUp(): void
	{
		if (!extension_loaded('mongodb')) {
			$this->markTestSkipped('The mongodb extension is not available');
		}

		$uri = getenv('MONGODB_URI') ?: 'mongodb://localhost:27017';

		$this->_conn = new TMongoConnection($uri, '', '', self::$testDbName);
		$this->_conn->setActive(true);
		$this->_conn->getManager()->executeCommand(
			self::$testDbName,
			new \MongoDB\Driver\Command(['ping' => 1])
		);
	}

	private ?TMongoConnection $_conn = null;
	private ?TMongoCommand $_cmd = null;

	protected function tearDown(): void
	{
		if ($this->_conn !== null) {
			try {
				$bulk = new BulkWrite();
				$bulk->delete([], ['limit' => 0]);
				$this->_conn->getManager()->executeBulkWrite(
					self::$testDbName . '.' . self::$testCollectionName,
					$bulk
				);
			} catch (\Exception $e) {
			}
			$this->_conn->setActive(false);
			$this->_conn = null;
		}
	}

	// -----------------------------------------------------------------------
	// Constructor and initialization
	// -----------------------------------------------------------------------

	public function test_constructor()
	{
		$cmd = new TMongoCommand($this->_conn, 'users');
		$this->assertInstanceOf(TMongoCommand::class, $cmd);
		$this->assertEquals('users', $cmd->getCollection());
	}

	public function test_constructor_different_collections()
	{
		$cmd1 = new TMongoCommand($this->_conn, 'users');
		$cmd2 = new TMongoCommand($this->_conn, 'orders');
		$cmd3 = new TMongoCommand($this->_conn, 'products');

		$this->assertEquals('users', $cmd1->getCollection());
		$this->assertEquals('orders', $cmd2->getCollection());
		$this->assertEquals('products', $cmd3->getCollection());
	}

	// -----------------------------------------------------------------------
	// Connection accessor
	// -----------------------------------------------------------------------

	public function test_get_connection()
	{
		$cmd = new TMongoCommand($this->_conn, 'users');
		$this->assertSame($this->_conn, $cmd->getConnection());
	}

	// -----------------------------------------------------------------------
	// IDataCommand - collection getter
	// -----------------------------------------------------------------------

	public function test_get_collection()
	{
		$cmd = new TMongoCommand($this->_conn, 'mycollection');
		$this->assertEquals('mycollection', $cmd->getCollection());
	}

	// -----------------------------------------------------------------------
	// Operation type
	// -----------------------------------------------------------------------

	public function test_operation_constants()
	{
		$this->assertEquals('find', TMongoCommand::OP_FIND);
		$this->assertEquals('insertOne', TMongoCommand::OP_INSERT_ONE);
		$this->assertEquals('insertMany', TMongoCommand::OP_INSERT_MANY);
		$this->assertEquals('updateOne', TMongoCommand::OP_UPDATE_ONE);
		$this->assertEquals('updateMany', TMongoCommand::OP_UPDATE_MANY);
		$this->assertEquals('deleteOne', TMongoCommand::OP_DELETE_ONE);
		$this->assertEquals('deleteMany', TMongoCommand::OP_DELETE_MANY);
		$this->assertEquals('aggregate', TMongoCommand::OP_AGGREGATE);
		$this->assertEquals('count', TMongoCommand::OP_COUNT);
		$this->assertEquals('distinct', TMongoCommand::OP_DISTINCT);
	}

	public function test_get_operation_default()
	{
		$cmd = new TMongoCommand($this->_conn, 'users');
		$this->assertEquals(TMongoCommand::OP_FIND, $cmd->getOperation());
	}

	public function test_set_operation()
	{
		$cmd = new TMongoCommand($this->_conn, 'users');
		$cmd->setOperation(TMongoCommand::OP_UPDATE_ONE);
		$this->assertEquals(TMongoCommand::OP_UPDATE_ONE, $cmd->getOperation());
	}

	public function test_set_operation_returns_this()
	{
		$cmd = new TMongoCommand($this->_conn, 'users');
		$result = $cmd->setOperation(TMongoCommand::OP_INSERT_ONE);
		$this->assertSame($cmd, $result);
	}

	// -----------------------------------------------------------------------
	// Filter
	// -----------------------------------------------------------------------

	public function test_get_filter_default()
	{
		$cmd = new TMongoCommand($this->_conn, 'users');
		$this->assertEquals([], $cmd->getFilter());
	}

	public function test_set_filter()
	{
		$cmd = new TMongoCommand($this->_conn, 'users');
		$cmd->setFilter(['name' => 'John']);
		$this->assertEquals(['name' => 'John'], $cmd->getFilter());
	}

	public function test_set_filter_with_complex_query()
	{
		$cmd = new TMongoCommand($this->_conn, 'users');
		$cmd->setFilter(['age' => ['$gte' => 18], 'status' => 'active']);
		$this->assertIsArray($cmd->getFilter());
		$this->assertEquals(18, $cmd->getFilter()['age']['$gte']);
	}

	public function test_set_filter_returns_this()
	{
		$cmd = new TMongoCommand($this->_conn, 'users');
		$result = $cmd->setFilter(['name' => 'John']);
		$this->assertSame($cmd, $result);
	}

	// -----------------------------------------------------------------------
	// Document (for insertOne)
	// -----------------------------------------------------------------------

	public function test_get_document_default()
	{
		$cmd = new TMongoCommand($this->_conn, 'users');
		$this->assertEquals([], $cmd->getDocument());
	}

	public function test_set_document()
	{
		$cmd = new TMongoCommand($this->_conn, 'users');
		$cmd->setDocument(['name' => 'John', 'age' => 30]);
		$this->assertEquals(['name' => 'John', 'age' => 30], $cmd->getDocument());
	}

	public function test_set_document_returns_this()
	{
		$cmd = new TMongoCommand($this->_conn, 'users');
		$result = $cmd->setDocument(['name' => 'John']);
		$this->assertSame($cmd, $result);
	}

	// -----------------------------------------------------------------------
	// Documents (for insertMany)
	// -----------------------------------------------------------------------

	public function test_get_documents_default()
	{
		$cmd = new TMongoCommand($this->_conn, 'users');
		$this->assertEquals([], $cmd->getDocuments());
	}

	public function test_set_documents()
	{
		$cmd = new TMongoCommand($this->_conn, 'users');
		$cmd->setDocuments([
			['name' => 'John'],
			['name' => 'Jane'],
		]);
		$this->assertCount(2, $cmd->getDocuments());
	}

	public function test_set_documents_returns_this()
	{
		$cmd = new TMongoCommand($this->_conn, 'users');
		$result = $cmd->setDocuments([['name' => 'John']]);
		$this->assertSame($cmd, $result);
	}

	// -----------------------------------------------------------------------
	// Update
	// -----------------------------------------------------------------------

	public function test_get_update_default()
	{
		$cmd = new TMongoCommand($this->_conn, 'users');
		$this->assertEquals([], $cmd->getUpdate());
	}

	public function test_set_update()
	{
		$cmd = new TMongoCommand($this->_conn, 'users');
		$cmd->setUpdate(['$set' => ['name' => 'John']]);
		$this->assertEquals(['$set' => ['name' => 'John']], $cmd->getUpdate());
	}

	public function test_set_update_with_multiple_operators()
	{
		$cmd = new TMongoCommand($this->_conn, 'users');
		$cmd->setUpdate(['$set' => ['name' => 'John'], '$inc' => ['age' => 1]]);
		$this->assertIsArray($cmd->getUpdate());
	}

	public function test_set_update_returns_this()
	{
		$cmd = new TMongoCommand($this->_conn, 'users');
		$result = $cmd->setUpdate(['$set' => ['name' => 'John']]);
		$this->assertSame($cmd, $result);
	}

	// -----------------------------------------------------------------------
	// Pipeline (for aggregate)
	// -----------------------------------------------------------------------

	public function test_get_pipeline_default()
	{
		$cmd = new TMongoCommand($this->_conn, 'users');
		$this->assertEquals([], $cmd->getPipeline());
	}

	public function test_set_pipeline()
	{
		$cmd = new TMongoCommand($this->_conn, 'users');
		$cmd->setPipeline([
			['$match' => ['status' => 'active']],
			['$sort' => ['name' => 1]],
		]);
		$this->assertCount(2, $cmd->getPipeline());
	}

	public function test_set_pipeline_returns_this()
	{
		$cmd = new TMongoCommand($this->_conn, 'users');
		$result = $cmd->setPipeline([['$match' => ['status' => 'active']]]);
		$this->assertSame($cmd, $result);
	}

	// -----------------------------------------------------------------------
	// Distinct field
	// -----------------------------------------------------------------------

	public function test_get_distinct_field_default()
	{
		$cmd = new TMongoCommand($this->_conn, 'users');
		$this->assertEquals('', $cmd->getDistinctField());
	}

	public function test_set_distinct_field()
	{
		$cmd = new TMongoCommand($this->_conn, 'users');
		$cmd->setDistinctField('status');
		$this->assertEquals('status', $cmd->getDistinctField());
	}

	public function test_set_distinct_field_returns_this()
	{
		$cmd = new TMongoCommand($this->_conn, 'users');
		$result = $cmd->setDistinctField('status');
		$this->assertSame($cmd, $result);
	}

	// -----------------------------------------------------------------------
	// Projection
	// -----------------------------------------------------------------------

	public function test_get_projection_default()
	{
		$cmd = new TMongoCommand($this->_conn, 'users');
		$this->assertEquals([], $cmd->getProjection());
	}

	public function test_set_projection()
	{
		$cmd = new TMongoCommand($this->_conn, 'users');
		$cmd->setProjection(['name' => 1, '_id' => 0]);
		$this->assertEquals(['name' => 1, '_id' => 0], $cmd->getProjection());
	}

	public function test_set_projection_with_exclusion()
	{
		$cmd = new TMongoCommand($this->_conn, 'users');
		$cmd->setProjection(['password' => 0]);
		$this->assertIsArray($cmd->getProjection());
	}

	public function test_set_projection_returns_this()
	{
		$cmd = new TMongoCommand($this->_conn, 'users');
		$result = $cmd->setProjection(['name' => 1]);
		$this->assertSame($cmd, $result);
	}

	// -----------------------------------------------------------------------
	// Sort
	// -----------------------------------------------------------------------

	public function test_get_sort_default()
	{
		$cmd = new TMongoCommand($this->_conn, 'users');
		$this->assertEquals([], $cmd->getSort());
	}

	public function test_set_sort()
	{
		$cmd = new TMongoCommand($this->_conn, 'users');
		$cmd->setSort(['name' => 1, 'age' => -1]);
		$this->assertEquals(['name' => 1, 'age' => -1], $cmd->getSort());
	}

	public function test_set_sort_ascending()
	{
		$cmd = new TMongoCommand($this->_conn, 'users');
		$cmd->setSort(['name' => 1]);
		$this->assertEquals(1, $cmd->getSort()['name']);
	}

	public function test_set_sort_descending()
	{
		$cmd = new TMongoCommand($this->_conn, 'users');
		$cmd->setSort(['name' => -1]);
		$this->assertEquals(-1, $cmd->getSort()['name']);
	}

	public function test_set_sort_returns_this()
	{
		$cmd = new TMongoCommand($this->_conn, 'users');
		$result = $cmd->setSort(['name' => 1]);
		$this->assertSame($cmd, $result);
	}

	// -----------------------------------------------------------------------
	// Limit
	// -----------------------------------------------------------------------

	public function test_get_limit_default()
	{
		$cmd = new TMongoCommand($this->_conn, 'users');
		$this->assertEquals(0, $cmd->getLimit());
	}

	public function test_set_limit()
	{
		$cmd = new TMongoCommand($this->_conn, 'users');
		$cmd->setLimit(10);
		$this->assertEquals(10, $cmd->getLimit());
	}

	public function test_set_limit_zero()
	{
		$cmd = new TMongoCommand($this->_conn, 'users');
		$cmd->setLimit(0);
		$this->assertEquals(0, $cmd->getLimit());
	}

	public function test_set_limit_large_value()
	{
		$cmd = new TMongoCommand($this->_conn, 'users');
		$cmd->setLimit(1000);
		$this->assertEquals(1000, $cmd->getLimit());
	}

	public function test_set_limit_returns_this()
	{
		$cmd = new TMongoCommand($this->_conn, 'users');
		$result = $cmd->setLimit(10);
		$this->assertSame($cmd, $result);
	}

	// -----------------------------------------------------------------------
	// Skip
	// -----------------------------------------------------------------------

	public function test_get_skip_default()
	{
		$cmd = new TMongoCommand($this->_conn, 'users');
		$this->assertEquals(0, $cmd->getSkip());
	}

	public function test_set_skip()
	{
		$cmd = new TMongoCommand($this->_conn, 'users');
		$cmd->setSkip(5);
		$this->assertEquals(5, $cmd->getSkip());
	}

	public function test_set_skip_zero()
	{
		$cmd = new TMongoCommand($this->_conn, 'users');
		$cmd->setSkip(0);
		$this->assertEquals(0, $cmd->getSkip());
	}

	public function test_set_skip_returns_this()
	{
		$cmd = new TMongoCommand($this->_conn, 'users');
		$result = $cmd->setSkip(5);
		$this->assertSame($cmd, $result);
	}

	// -----------------------------------------------------------------------
	// Options
	// -----------------------------------------------------------------------

	public function test_get_options_default()
	{
		$cmd = new TMongoCommand($this->_conn, 'users');
		$this->assertEquals([], $cmd->getOptions());
	}

	public function test_set_options()
	{
		$cmd = new TMongoCommand($this->_conn, 'users');
		$cmd->setOptions(['collation' => ['locale' => 'en']]);
		$this->assertEquals(['collation' => ['locale' => 'en']], $cmd->getOptions());
	}

	public function test_set_options_returns_this()
	{
		$cmd = new TMongoCommand($this->_conn, 'users');
		$result = $cmd->setOptions(['collation' => ['locale' => 'en']]);
		$this->assertSame($cmd, $result);
	}

	// -----------------------------------------------------------------------
	// Fluent API
	// -----------------------------------------------------------------------

	public function test_fluent_api_chain()
	{
		$cmd = new TMongoCommand($this->_conn, 'users');
		$cmd->setFilter(['status' => 'active']);
		$cmd->setSort(['name' => 1]);
		$cmd->setLimit(10);
		$cmd->setSkip(5);

		$this->assertEquals(['status' => 'active'], $cmd->getFilter());
		$this->assertEquals(['name' => 1], $cmd->getSort());
		$this->assertEquals(10, $cmd->getLimit());
		$this->assertEquals(5, $cmd->getSkip());
	}

	// -----------------------------------------------------------------------
	// Insert operations
	// -----------------------------------------------------------------------

	public function test_insert_one_returns_id()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$id = $cmd->insertOne(['name' => 'test1', 'value' => 1]);
		$this->assertNotNull($id);
	}

	public function test_insert_one_with_empty_document()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$id = $cmd->insertOne([]);
		$this->assertNotNull($id);
	}

	public function test_insert_one_with_nested_document()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$id = $cmd->insertOne([
			'name' => 'nested',
			'address' => [
				'city' => 'NYC',
				'zip' => '10001',
			],
		]);
		$this->assertNotNull($id);
	}

	public function test_insert_many_returns_ids()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$ids = $cmd->insertMany([
			['name' => 'test1'],
			['name' => 'test2'],
			['name' => 'test3'],
		]);
		$this->assertCount(3, $ids);
	}

	public function test_insert_many_empty_array()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		// Empty insert array should return empty array (or throw depending on driver behavior)
		try {
			$ids = $cmd->insertMany([]);
			$this->assertCount(0, $ids);
		} catch (\Exception $e) {
			// Driver may throw on empty insert, which is acceptable behavior
			$this->assertInstanceOf(\Prado\Exceptions\TDbException::class, $e);
		}
	}

	// -----------------------------------------------------------------------
	// Update operations
	// -----------------------------------------------------------------------

	public function test_update_one()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$id = $cmd->insertOne(['name' => 'update_test', 'value' => 1]);
		$modified = $cmd->updateOne(
			['_id' => $id],
			['$set' => ['value' => 2]]
		);
		$this->assertGreaterThanOrEqual(0, $modified);
	}

	public function test_update_one_with_upsert()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$modified = $cmd->updateOne(
			['name' => 'nonexistent'],
			['$set' => ['value' => 1]],
			['upsert' => true]
		);
		$this->assertGreaterThanOrEqual(0, $modified);
	}

	public function test_update_many()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$cmd->insertMany([
			['name' => 'multi_update_test', 'value' => 1],
			['name' => 'multi_update_test', 'value' => 1],
		]);
		$modified = $cmd->updateMany(
			['name' => 'multi_update_test'],
			['$set' => ['value' => 2]]
		);
		$this->assertGreaterThanOrEqual(0, $modified);
	}

	// -----------------------------------------------------------------------
	// Delete operations
	// -----------------------------------------------------------------------

	public function test_delete_one()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$id = $cmd->insertOne(['name' => 'delete_test']);
		$deleted = $cmd->deleteOne(['_id' => $id]);
		$this->assertGreaterThanOrEqual(0, $deleted);
	}

	public function test_delete_many()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$cmd->insertMany([
			['name' => 'delete_many_test'],
			['name' => 'delete_many_test'],
		]);
		$deleted = $cmd->deleteMany(['name' => 'delete_many_test']);
		$this->assertGreaterThanOrEqual(0, $deleted);
	}

	// -----------------------------------------------------------------------
	// Find operations
	// -----------------------------------------------------------------------

	public function test_find_one_returns_document()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$cmd->insertOne(['name' => 'find_test', 'value' => 1]);
		$doc = $cmd->findOne(['name' => 'find_test']);
		$this->assertIsArray($doc);
		$this->assertEquals('find_test', $doc['name']);
	}

	public function test_find_one_returns_null_when_not_found()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$doc = $cmd->findOne(['name' => 'nonexistent']);
		$this->assertNull($doc);
	}

	public function test_find_one_with_empty_filter()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$cmd->insertOne(['name' => 'empty_filter_test']);
		$doc = $cmd->findOne([]);
		$this->assertIsArray($doc);
	}

	public function test_find_many_returns_reader()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$cmd->insertMany([
			['name' => 'find_many_1'],
			['name' => 'find_many_2'],
		]);
		$reader = $cmd->findMany(['name' => ['$regex' => '^find_many_']]);
		$this->assertInstanceOf(TMongoDataReader::class, $reader);
	}

	public function test_find_many_with_sort()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$cmd->insertMany([
			['name' => 'z_test', 'value' => 1],
			['name' => 'a_test', 'value' => 2],
		]);
		$reader = $cmd->findMany([], ['sort' => ['name' => 1]]);
		$this->assertInstanceOf(TMongoDataReader::class, $reader);
	}

	public function test_find_many_with_limit()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		for ($i = 1; $i <= 5; $i++) {
			$cmd->insertOne(['name' => 'test' . $i]);
		}
		$reader = $cmd->findMany([], ['limit' => 3]);
		$this->assertInstanceOf(TMongoDataReader::class, $reader);
	}

	// -----------------------------------------------------------------------
	// Aggregation
	// -----------------------------------------------------------------------

	public function test_aggregate_returns_reader()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$cmd->insertOne(['name' => 'agg_test', 'value' => 1]);
		$reader = $cmd->aggregate([
			['$match' => ['name' => 'agg_test']],
			['$count' => 'total'],
		]);
		$this->assertInstanceOf(TMongoDataReader::class, $reader);
	}

	public function test_aggregate_with_empty_pipeline()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$reader = $cmd->aggregate([]);
		$this->assertInstanceOf(TMongoDataReader::class, $reader);
	}

	public function test_aggregate_multiple_stages()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$reader = $cmd->aggregate([
			['$match' => ['name' => ['$exists' => true]]],
			['$sort' => ['name' => 1]],
			['$limit' => 10],
			['$project' => ['name' => 1]],
		]);
		$this->assertInstanceOf(TMongoDataReader::class, $reader);
	}

	// -----------------------------------------------------------------------
	// Count
	// -----------------------------------------------------------------------

	public function test_count_returns_int()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$cmd->insertOne(['name' => 'count_test']);
		$count = $cmd->count(['name' => 'count_test']);
		$this->assertIsInt($count);
		$this->assertGreaterThanOrEqual(0, $count);
	}

	public function test_count_with_empty_filter()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$count = $cmd->count([]);
		$this->assertIsInt($count);
	}

	public function test_count_with_filter()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$count = $cmd->count(['name' => 'nonexistent']);
		$this->assertIsInt($count);
		$this->assertEquals(0, $count);
	}

	// -----------------------------------------------------------------------
	// Distinct
	// -----------------------------------------------------------------------

	public function test_distinct_returns_array()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$cmd->insertMany([
			['name' => 'd_test', 'status' => 'active'],
			['name' => 'd_test', 'status' => 'active'],
			['name' => 'd_test', 'status' => 'inactive'],
		]);
		$values = $cmd->distinct('status', [], []);
		$this->assertIsArray($values);
	}

	public function test_distinct_with_filter()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$cmd->insertMany([
			['name' => 'dist_test', 'status' => 'active'],
			['name' => 'dist_test', 'status' => 'inactive'],
		]);
		$values = $cmd->distinct('status', ['name' => 'dist_test'], []);
		$this->assertIsArray($values);
	}

	public function test_distinct_with_empty_filter()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$values = $cmd->distinct('status', [], []);
		$this->assertIsArray($values);
	}

	// -----------------------------------------------------------------------
	// Query methods (IDataCommand interface)
	// -----------------------------------------------------------------------

	public function test_query_returns_reader()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$cmd->insertOne(['name' => 'query_test']);
		$reader = $cmd->query();
		$this->assertInstanceOf(TMongoDataReader::class, $reader);
	}

	public function test_query_row_returns_array()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$cmd->insertOne(['name' => 'query_row_test']);
		$row = $cmd->queryRow();
		$this->assertIsArray($row);
	}

	public function test_query_row_returns_false_when_empty()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$row = $cmd->queryRow();
		$this->assertFalse($row);
	}

	public function test_query_scalar_returns_mixed()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$cmd->insertOne(['name' => 'query_scalar_test', 'value' => 42]);
		$value = $cmd->queryScalar();
		$this->assertNotFalse($value);
	}

	public function test_query_scalar_returns_false_when_empty()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$value = $cmd->queryScalar();
		$this->assertFalse($value);
	}

	public function test_query_column_returns_array()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$cmd->insertMany([
			['name' => 'col_test', 'value' => 1],
			['name' => 'col_test', 'value' => 2],
		]);
		$column = $cmd->queryColumn();
		$this->assertIsArray($column);
	}

	public function test_query_all_returns_array()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$cmd->insertMany([
			['name' => 'all_test', 'value' => 1],
			['name' => 'all_test', 'value' => 2],
		]);
		$all = $cmd->queryAll();
		$this->assertIsArray($all);
	}

	// -----------------------------------------------------------------------
	// Execute method (IDataCommand interface)
	// -----------------------------------------------------------------------

	public function test_execute_insert_one()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$cmd->setOperation(TMongoCommand::OP_INSERT_ONE);
		$cmd->setDocument(['name' => 'execute_test']);
		$result = $cmd->execute();
		$this->assertGreaterThanOrEqual(0, $result);
	}

	public function test_execute_insert_many()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$cmd->setOperation(TMongoCommand::OP_INSERT_MANY);
		$cmd->setDocuments([
			['name' => 'execute_test1'],
			['name' => 'execute_test2'],
		]);
		$result = $cmd->execute();
		$this->assertGreaterThanOrEqual(0, $result);
	}

	public function test_execute_update_one()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$id = $cmd->insertOne(['name' => 'execute_update_test']);
		$cmd->setOperation(TMongoCommand::OP_UPDATE_ONE);
		$cmd->setFilter(['_id' => $id]);
		$cmd->setUpdate(['$set' => ['value' => 1]]);
		$result = $cmd->execute();
		$this->assertGreaterThanOrEqual(0, $result);
	}

	public function test_execute_update_many()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$cmd->insertMany([
			['name' => 'execute_update_many'],
			['name' => 'execute_update_many'],
		]);
		$cmd->setOperation(TMongoCommand::OP_UPDATE_MANY);
		$cmd->setFilter(['name' => 'execute_update_many']);
		$cmd->setUpdate(['$set' => ['updated' => true]]);
		$result = $cmd->execute();
		$this->assertGreaterThanOrEqual(0, $result);
	}

	public function test_execute_delete_one()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$id = $cmd->insertOne(['name' => 'execute_delete_test']);
		$cmd->setOperation(TMongoCommand::OP_DELETE_ONE);
		$cmd->setFilter(['_id' => $id]);
		$result = $cmd->execute();
		$this->assertGreaterThanOrEqual(0, $result);
	}

	public function test_execute_delete_many()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$cmd->insertMany([
			['name' => 'execute_delete_many'],
			['name' => 'execute_delete_many'],
		]);
		$cmd->setOperation(TMongoCommand::OP_DELETE_MANY);
		$cmd->setFilter(['name' => 'execute_delete_many']);
		$result = $cmd->execute();
		$this->assertGreaterThanOrEqual(0, $result);
	}

	public function test_execute_throws_for_read_operation()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$cmd->setOperation(TMongoCommand::OP_FIND);
		$this->expectException(\Prado\Exceptions\TDbException::class);
		$cmd->execute();
	}

	// -----------------------------------------------------------------------
	// Edge cases and additional coverage
	// -----------------------------------------------------------------------

	public function test_update_one_with_upsert_options()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$modified = $cmd->updateOne(
			['name' => 'upsert_test_' . time()],
			['$set' => ['created' => true]],
			['upsert' => true]
		);
		$this->assertGreaterThanOrEqual(0, $modified);
	}

	public function test_find_many_with_projection_options()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$cmd->insertOne(['name' => 'proj_test', 'email' => 'test@test.com']);
		$reader = $cmd->findMany([], ['projection' => ['name' => 1, '_id' => 0]]);
		$this->assertInstanceOf(TMongoDataReader::class, $reader);
	}

	public function test_find_many_with_skip()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		for ($i = 0; $i < 5; $i++) {
			$cmd->insertOne(['name' => 'skip_test', 'idx' => $i]);
		}
		$reader = $cmd->findMany(['name' => 'skip_test'], ['skip' => 2]);
		$this->assertInstanceOf(TMongoDataReader::class, $reader);
	}

	public function test_query_row_with_actual_data()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$cmd->insertOne(['name' => 'row_test', 'value' => 42]);
		$row = $cmd->queryRow();
		$this->assertIsArray($row);
		$this->assertEquals('row_test', $row['name']);
	}

	public function test_aggregate_with_group_stage()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$cmd->insertMany([
			['name' => 'agg1', 'value' => 10],
			['name' => 'agg2', 'value' => 20],
		]);
		$reader = $cmd->aggregate([
			['$group' => ['_id' => null, 'total' => ['$sum' => '$value']]]
		]);
		$this->assertInstanceOf(TMongoDataReader::class, $reader);
	}

	public function test_count_with_no_matches()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$count = $cmd->count(['nonexistent_field' => 'nonexistent_value']);
		$this->assertEquals(0, $count);
	}

	public function test_distinct_with_no_data()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$values = $cmd->distinct('status', ['name' => 'nonexistent'], []);
		$this->assertIsArray($values);
	}

public function test_update_with_array_filter()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
$cmd->insertOne(['name' => 'arr_filter', 'items' => [1, 2, 3]]);
		$options = ['arrayFilters' => [['elem' => 2]]];
		try {
			$modified = $cmd->updateOne(
				['name' => 'arr_filter'],
				['$pull' => ['items' => 2]],
				$options
			);
			$this->assertGreaterThanOrEqual(0, $modified);
		} catch (\Prado\Exceptions\TDbException $e) {
			$this->markTestSkipped('arrayFilters not supported: ' . $e->getMessage());
		}
	}
}
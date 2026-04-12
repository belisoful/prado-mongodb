<?php

use Prado\Data\DataGateway\TMongoCollectionGateway;
use Prado\Data\TMongoConnection;
use Prado\Data\TMongoDataReader;

class TMongoCollectionGatewayTest extends PHPUnit\Framework\TestCase
{
	private ?TMongoConnection $_conn = null;
	private ?TMongoCollectionGateway $_gateway = null;

	/** @var array ObjectIds inserted during each test, cleaned up in tearDown. */
	private array $_insertedIds = [];

	protected function setUp(): void
	{
		if (!extension_loaded('mongodb')) {
			$this->markTestSkipped('The mongodb extension is not available.');
		}

		$uri = getenv('MONGODB_URI') ?: 'mongodb://localhost:27017';
		$db = getenv('MONGODB_DATABASE') ?: 'prado_unitest';

		try {
			$this->_conn = new TMongoConnection($uri, '', '', $db);
			$this->_conn->setActive(true);
			$this->_gateway = new TMongoCollectionGateway('table1', $this->_conn);
		} catch (\Exception $e) {
			$this->markTestSkipped('Cannot connect to MongoDB: ' . $e->getMessage());
		}
	}

	protected function tearDown(): void
	{
		// Remove any documents inserted during the test to keep the collection clean.
		if ($this->_gateway !== null && $this->_insertedIds !== []) {
			try {
				$this->_gateway->delete(['_id' => ['$in' => $this->_insertedIds]]);
			} catch (\Exception $e) {
				// Best-effort cleanup — ignore errors.
			}
		}
		$this->_insertedIds = [];
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	private function insertTestDoc(array $extra = []): mixed
	{
		$doc = array_merge([
			'name' => 'gateway_test',
			'field1_int' => 1,
			'field4_double' => 1.0,
			'field5_double' => 0.0,
			'field6_date' => new \MongoDB\BSON\UTCDateTime(),
			'field7_string' => '00:00:00',
			'field8_int' => 0,
			'field10_bool' => false,
		], $extra);

		$id = $this->_gateway->insert($doc);
		$this->_insertedIds[] = $id;
		return $id;
	}

	// -----------------------------------------------------------------------
	// findAll / count
	// -----------------------------------------------------------------------

	public function test_find_all_returns_reader()
	{
		$reader = $this->_gateway->findAll();
		$this->assertInstanceOf(TMongoDataReader::class, $reader);
		$this->assertGreaterThanOrEqual(0, $reader->getRowCount());
	}

	public function test_count_all()
	{
		$count = $this->_gateway->count();
		$this->assertIsInt($count);
		$this->assertGreaterThanOrEqual(0, $count);
	}

	// -----------------------------------------------------------------------
	// insert / findById / updateById / deleteById
	// -----------------------------------------------------------------------

	public function test_insert_returns_id()
	{
		$id = $this->insertTestDoc(['name' => 'insert_test']);
		$this->assertNotNull($id);
	}

	public function test_find_by_id()
	{
		$id = $this->insertTestDoc(['name' => 'find_by_id_test']);
		$doc = $this->_gateway->findById($id);
		$this->assertNotNull($doc);
		$this->assertEquals('find_by_id_test', $doc['name']);
	}

	public function test_find_returns_null_for_missing_id()
	{
		// Use a valid-format but non-existent ObjectId
		$doc = $this->_gateway->findById('000000000000000000000001');
		$this->assertNull($doc);
	}

	public function test_find_with_filter()
	{
		$id = $this->insertTestDoc(['name' => 'filter_test', 'field10_bool' => true]);
		$doc = $this->_gateway->find(['name' => 'filter_test', 'field10_bool' => true]);
		$this->assertNotNull($doc);
		$this->assertEquals('filter_test', $doc['name']);
	}

	public function test_update_by_id()
	{
		$id = $this->insertTestDoc(['name' => 'update_test']);
		$modified = $this->_gateway->updateById($id, ['$set' => ['name' => 'updated']]);
		$this->assertEquals(1, $modified);

		$doc = $this->_gateway->findById($id);
		$this->assertEquals('updated', $doc['name']);
	}

	public function test_delete_by_id()
	{
		$id = $this->insertTestDoc(['name' => 'delete_test']);

		// Verify it exists
		$this->assertNotNull($this->_gateway->findById($id));

		$deleted = $this->_gateway->deleteById($id);
		$this->assertEquals(1, $deleted);

		// Remove from cleanup list since we already deleted it
		$this->_insertedIds = array_filter(
			$this->_insertedIds,
			fn ($existing) => (string) $existing !== (string) $id
		);

		$this->assertNull($this->_gateway->findById($id));
	}

	// -----------------------------------------------------------------------
	// insertMany / findAllByIds
	// -----------------------------------------------------------------------

	public function test_insert_many()
	{
		$docs = [
			['name' => 'batch_a', 'field1_int' => 1, 'field4_double' => 0.0, 'field5_double' => 0.0, 'field6_date' => new \MongoDB\BSON\UTCDateTime(), 'field7_string' => '00:00:00', 'field8_int' => 0, 'field10_bool' => false],
			['name' => 'batch_b', 'field1_int' => 2, 'field4_double' => 0.0, 'field5_double' => 0.0, 'field6_date' => new \MongoDB\BSON\UTCDateTime(), 'field7_string' => '00:00:00', 'field8_int' => 0, 'field10_bool' => false],
		];
		$ids = $this->_gateway->insertMany($docs);
		$this->assertCount(2, $ids);
		foreach ($ids as $id) {
			$this->_insertedIds[] = $id;
		}
	}

	public function test_find_all_by_ids()
	{
		$id1 = $this->insertTestDoc(['name' => 'byids_a']);
		$id2 = $this->insertTestDoc(['name' => 'byids_b']);

		$reader = $this->_gateway->findAllByIds([$id1, $id2]);
		$this->assertInstanceOf(TMongoDataReader::class, $reader);
		$this->assertEquals(2, $reader->getRowCount());
	}

	// -----------------------------------------------------------------------
	// update (multi) / delete (multi)
	// -----------------------------------------------------------------------

	public function test_update_many()
	{
		$id1 = $this->insertTestDoc(['name' => 'multi_update', 'field10_bool' => false]);
		$id2 = $this->insertTestDoc(['name' => 'multi_update', 'field10_bool' => false]);

		$modified = $this->_gateway->update(
			['name' => 'multi_update'],
			['$set' => ['field10_bool' => true]]
		);
		$this->assertGreaterThanOrEqual(2, $modified);

		$doc1 = $this->_gateway->findById($id1);
		$this->assertTrue($doc1['field10_bool']);
	}

	public function test_delete_many()
	{
		$this->insertTestDoc(['name' => 'to_delete_many']);
		$this->insertTestDoc(['name' => 'to_delete_many']);

		$before = $this->_gateway->count(['name' => 'to_delete_many']);
		$deleted = $this->_gateway->delete(['name' => 'to_delete_many']);
		$this->assertGreaterThanOrEqual(2, $deleted);

		$after = $this->_gateway->count(['name' => 'to_delete_many']);
		$this->assertEquals(0, $after);

		// Already deleted — remove from cleanup list
		$this->_insertedIds = [];
	}

	// -----------------------------------------------------------------------
	// count with filter
	// -----------------------------------------------------------------------

	public function test_count_with_filter()
	{
		$id1 = $this->insertTestDoc(['name' => 'count_filter', 'field10_bool' => true]);
		$id2 = $this->insertTestDoc(['name' => 'count_filter', 'field10_bool' => false]);

		$countTrue = $this->_gateway->count(['name' => 'count_filter', 'field10_bool' => true]);
		$this->assertEquals(1, $countTrue);
	}

	// -----------------------------------------------------------------------
	// aggregate
	// -----------------------------------------------------------------------

	public function test_aggregate_count()
	{
		$id = $this->insertTestDoc(['name' => 'agg_test']);

		$pipeline = [
			['$match' => ['name' => 'agg_test']],
			['$count' => 'total'],
		];
		$reader = $this->_gateway->aggregate($pipeline);
		$this->assertInstanceOf(TMongoDataReader::class, $reader);
		$result = $reader->readAll();
		$this->assertGreaterThanOrEqual(1, $result[0]['total'] ?? 0);
	}

	// -----------------------------------------------------------------------
	// Dynamic finders (__call)
	// -----------------------------------------------------------------------

	public function test_find_by_name()
	{
		$id = $this->insertTestDoc(['name' => 'dynamic_finder_test']);
		$doc = $this->_gateway->findByName('dynamic_finder_test');
		$this->assertNotNull($doc);
		$this->assertEquals('dynamic_finder_test', $doc['name']);
	}

	public function test_find_all_by_name()
	{
		$id1 = $this->insertTestDoc(['name' => 'dynamic_all_test']);
		$id2 = $this->insertTestDoc(['name' => 'dynamic_all_test']);
		$reader = $this->_gateway->findAllByName('dynamic_all_test');
		$this->assertInstanceOf(TMongoDataReader::class, $reader);
		$this->assertEquals(2, $reader->getRowCount());
	}

	public function test_delete_by_name()
	{
		$this->insertTestDoc(['name' => 'dynamic_delete_test']);
		$deleted = $this->_gateway->deleteByName('dynamic_delete_test');
		$this->assertGreaterThanOrEqual(1, $deleted);
		// Already deleted
		$this->_insertedIds = [];
	}

	// -----------------------------------------------------------------------
	// String ID normalisation
	// -----------------------------------------------------------------------

	public function test_find_by_id_with_string_id()
	{
		$id = $this->insertTestDoc(['name' => 'string_id_test']);
		// Pass the ObjectId as a hex string
		$doc = $this->_gateway->findById((string) $id);
		$this->assertNotNull($doc);
		$this->assertEquals('string_id_test', $doc['name']);
	}

	// -----------------------------------------------------------------------
	// Accessor methods
	// -----------------------------------------------------------------------

	public function test_get_db_connection()
	{
		$this->assertSame($this->_conn, $this->_gateway->getDbConnection());
	}

	public function test_get_collection_name()
	{
		$this->assertEquals('table1', $this->_gateway->getCollectionName());
	}
}

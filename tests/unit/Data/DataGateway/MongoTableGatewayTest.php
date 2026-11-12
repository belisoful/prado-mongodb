<?php

/**
 * MongoTableGatewayTest drives the framework's own driver-agnostic consumers,
 * {@see \Prado\Data\DataGateway\TTableGateway} and
 * {@see \Prado\Data\DataGateway\TDataGatewayCommand}, against a live MongoDB
 * collection through {@see \Prado\Data\Common\Mongo\TMongoCommandBuilder}.
 *
 * Requires a live MongoDB instance; skipped when unavailable.
 */

use Prado\Data\DataGateway\TMongoQueryCriteria;
use Prado\Data\DataGateway\TTableGateway;
use Prado\Data\TMongoConnection;

class MongoTableGatewayTest extends PHPUnit\Framework\TestCase
{
	private const COLLECTION = 'tg_portability_test';

	private ?TMongoConnection $_conn = null;

	protected function setUp(): void
	{
		if (!extension_loaded('mongodb')) {
			$this->markTestSkipped('The mongodb extension is not available.');
		}
		$uri = getenv('MONGODB_URI') ?: 'mongodb://localhost:27017';
		$db = getenv('MONGODB_DATABASE') ?: 'prado_unitest';
		$this->_conn = new TMongoConnection($uri, '', '', $db);
		try {
			$this->_conn->setActive(true);
			$this->_conn->getManager()->executeCommand($db, new \MongoDB\Driver\Command(['ping' => 1]));
			$this->dropCollection();
		} catch (\Exception $e) {
			$this->markTestSkipped('MongoDB is not available: ' . $e->getMessage());
		}
	}

	protected function tearDown(): void
	{
		if ($this->_conn !== null && $this->_conn->getActive()) {
			$this->dropCollection();
		}
	}

	private function dropCollection(): void
	{
		try {
			$this->_conn->getManager()->executeCommand(
				$this->_conn->getDatabaseName(),
				new \MongoDB\Driver\Command(['drop' => self::COLLECTION])
			);
		} catch (\Exception $e) {
			// The collection may not exist.
		}
	}

	private function gateway(): TTableGateway
	{
		return new TTableGateway(self::COLLECTION, $this->_conn);
	}

	private function raw(array $filter): ?array
	{
		return $this->_conn->createCommand(self::COLLECTION)->findOne($filter);
	}

	public function test_table_gateway_crud_roundtrip(): void
	{
		$gateway = $this->gateway();
		$this->assertFalse($gateway->getTableExists());

		$id = $gateway->insert(['name' => 'Ann', 'age' => 31]);
		$this->assertNotEmpty($id);
		$this->assertTrue($gateway->getTableExists());

		$row = $gateway->findByPk($id);
		$this->assertSame('Ann', $row['name']);

		$this->assertSame(1, $gateway->count());
		$this->assertSame(1, $gateway->deleteByPk($id));
		$this->assertEmpty($gateway->findByPk($id));
	}

	public function test_table_gateway_accepts_a_mongo_criteria(): void
	{
		$gateway = $this->gateway();
		$gateway->insert(['name' => 'Ann', 'age' => 31]);
		$gateway->insert(['name' => 'Bob', 'age' => 44]);

		try {
			$count = $gateway->count(new TMongoQueryCriteria(['age' => 31]));
		} catch (\Prado\Exceptions\TDbException $e) {
			if ($e->getErrorCode() !== 'dbtablegateway_invalid_criteria') {
				throw $e;
			}
			// PRADO before pradosoft/prado#1514 (4.4) and #1519 (4.3.3).
			$this->markTestSkipped('This PRADO TTableGateway accepts only a string or a TSqlCriteria, not any IDataCriteria.');
		}
		$this->assertSame(1, $count);
		$this->assertSame(1, $gateway->deleteAll(new TMongoQueryCriteria(['age' => 44])));
		$this->assertSame(1, $gateway->count());
	}

	public function test_table_gateway_rejects_sql_condition(): void
	{
		$gateway = $this->gateway();
		$gateway->insert(['name' => 'Ann']);

		$this->expectException(\Prado\Exceptions\TDbException::class);
		$gateway->deleteAll('name = ?', 'Ann');
	}

	public function test_insert_or_ignore_inserts_then_ignores(): void
	{
		$gateway = $this->gateway();
		$gateway->insert(['name' => 'existing']);

		// No _id: must insert even though the collection is not empty.
		$this->assertNotFalse($gateway->insertOrIgnore(['name' => 'Bea']));
		$this->assertNotNull($this->raw(['name' => 'Bea']));

		$this->assertNotFalse($gateway->insertOrIgnore(['_id' => 'fixed', 'name' => 'Cal']));
		$this->assertFalse($gateway->insertOrIgnore(['_id' => 'fixed', 'name' => 'Changed']));
		$this->assertSame('Cal', $this->raw(['_id' => 'fixed'])['name']);
	}

	public function test_upsert_inserts_full_document_and_updates_listed_fields(): void
	{
		$gateway = $this->gateway();
		$gateway->insert(['email' => 'other@example.com', 'name' => 'Other']);

		$first = $gateway->upsert(['email' => 'dee@example.com', 'name' => 'Dee', 'score' => 1], ['score'], ['email']);
		$this->assertNotFalse($first);
		$doc = $this->raw(['email' => 'dee@example.com']);
		$this->assertSame('Dee', $doc['name']);
		$this->assertSame(1, $doc['score']);

		$second = $gateway->upsert(['email' => 'dee@example.com', 'name' => 'Ignored', 'score' => 2], ['score'], ['email']);
		$this->assertNotFalse($second);
		$doc = $this->raw(['email' => 'dee@example.com']);
		$this->assertSame('Dee', $doc['name']);
		$this->assertSame(2, $doc['score']);
		$this->assertSame('Other', $this->raw(['email' => 'other@example.com'])['name']);
	}

	public function test_upsert_without_conflict_fields_inserts(): void
	{
		$gateway = $this->gateway();
		$gateway->insert(['name' => 'Eve']);

		$this->assertNotFalse($gateway->upsert(['name' => 'Fay']));
		$this->assertSame(2, $gateway->count(null));
		$this->assertSame('Eve', $this->raw(['name' => 'Eve'])['name']);
	}
}

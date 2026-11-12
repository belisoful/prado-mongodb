<?php

/**
 * MongoTransactionTest — integration test for multi-document transactions
 * against a live MongoDB connection.
 *
 * Multi-document transactions require a replica set (or sharded) topology and
 * MongoDB 4.0+.  Each test begins a transaction via
 * {@see TMongoConnection::beginTransaction}; if the running server rejects
 * transactions (e.g. a standalone mongod), the test is skipped rather than
 * failed.
 *
 * The tests prove that while a transaction is active every command created on
 * the connection — both the low-level gateway ({@see TMongoCommand}) and the
 * ActiveRecord persistence path — automatically enlists in the transaction
 * session, so commit/rollback are atomic across multiple collections.
 */

use Prado\Data\ActiveRecord\TActiveRecord;
use Prado\Data\ActiveRecord\TActiveRecordManager;
use Prado\Data\TMongoConnection;

if (class_exists(TActiveRecord::class)) {
	class TxAccount extends TActiveRecord
	{
		public const TABLE = 'tx_account';
		public $_id;
		public $owner;
		public $balance;

		public static function finder($className = __CLASS__)
		{
			return parent::finder($className);
		}
	}
}

class MongoTransactionTest extends PHPUnit\Framework\TestCase
{
	use MongoActiveRecordCapabilityTrait;

	private const COLLECTIONS = ['tx_orders', 'tx_inventory', 'tx_account'];

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
			$manager = $this->_conn->getManager();
			$manager->executeCommand($db, new \MongoDB\Driver\Command(['ping' => 1]));
			foreach (self::COLLECTIONS as $coll) {
				try {
					$manager->executeCommand($db, new \MongoDB\Driver\Command(['drop' => $coll]));
				} catch (\Exception $e) {
					// ignore
				}
			}
		} catch (\Exception $e) {
			$this->markTestSkipped('MongoDB is not available: ' . $e->getMessage());
		}

		// Use a FRESH record manager bound to this test's connection.  The
		// manager is a process-wide singleton that caches per-class metadata
		// (and with it the command builder's connection); a stale cached
		// connection from an earlier test would not carry this test's
		// transaction session, so AR writes would not enlist.  Real
		// applications use one stable connection and never hit this.
		if (class_exists(TActiveRecord::class)) {
			$manager = new TActiveRecordManager();
			$manager->setDbConnection($this->_conn);
			TActiveRecordManager::getInstance($manager);
		}
	}

	protected function tearDown(): void
	{
		if ($this->_conn !== null && $this->_conn->getActive()) {
			// Defensively roll back any transaction a failing test left open.
			$tx = $this->_conn->getCurrentTransaction();
			if ($tx !== null && $tx->getActive()) {
				try {
					$tx->rollback();
				} catch (\Exception $e) {
					// ignore
				}
			}
			$db = $this->_conn->getDatabaseName();
			foreach (self::COLLECTIONS as $coll) {
				try {
					$this->_conn->getManager()->executeCommand($db, new \MongoDB\Driver\Command(['drop' => $coll]));
				} catch (\Exception $e) {
					// ignore
				}
			}
		}
	}

	/**
	 * Begins a transaction, skipping the test if the server topology does not
	 * support multi-document transactions (e.g. a standalone mongod).
	 */
	private function beginOrSkip()
	{
		try {
			return $this->_conn->beginTransaction();
		} catch (\Exception $e) {
			$this->markTestSkipped('Multi-document transactions unsupported (replica set required): ' . $e->getMessage());
		}
	}

	private function countDocs(string $collection): int
	{
		return $this->_conn->createCommand($collection)->count([]);
	}

	// -----------------------------------------------------------------------
	// Gateway-level transaction across two collections
	// -----------------------------------------------------------------------

	public function test_commit_persists_across_collections(): void
	{
		$tx = $this->beginOrSkip();

		$this->_conn->createCommand('tx_orders')->insertOne(['item' => 'widget', 'qty' => 1]);
		$this->_conn->createCommand('tx_inventory')->insertOne(['item' => 'widget', 'stock' => 41]);

		$tx->commit();

		$this->assertSame(1, $this->countDocs('tx_orders'));
		$this->assertSame(1, $this->countDocs('tx_inventory'));
	}

	public function test_rollback_discards_all_writes(): void
	{
		$tx = $this->beginOrSkip();

		$this->_conn->createCommand('tx_orders')->insertOne(['item' => 'gadget', 'qty' => 5]);
		$this->_conn->createCommand('tx_inventory')->insertOne(['item' => 'gadget', 'stock' => 10]);

		$tx->rollback();

		$this->assertSame(0, $this->countDocs('tx_orders'));
		$this->assertSame(0, $this->countDocs('tx_inventory'));
	}

	// -----------------------------------------------------------------------
	// ActiveRecord persistence enlists in the active transaction
	// -----------------------------------------------------------------------

	public function test_active_record_commit(): void
	{
		$this->requireActiveRecord();
		$tx = $this->beginOrSkip();

		$a = new TxAccount();
		$a->owner = 'Alice';
		$a->balance = 100;
		$a->save();

		$b = new TxAccount();
		$b->owner = 'Bob';
		$b->balance = 50;
		$b->save();

		$tx->commit();

		$this->assertSame(2, $this->countDocs('tx_account'));
		$this->assertSame(2, TxAccount::finder()->count());
	}

	public function test_active_record_rollback(): void
	{
		$this->requireActiveRecord();
		$tx = $this->beginOrSkip();

		$a = new TxAccount();
		$a->owner = 'Carol';
		$a->balance = 100;
		$a->save();
		$this->assertNotNull($a->_id); // write happened inside the transaction

		$tx->rollback();

		$this->assertSame(0, $this->countDocs('tx_account'));
		$this->assertNull(TxAccount::finder()->findByPk($a->_id));
	}

	// -----------------------------------------------------------------------
	// Connection state during/after a transaction
	// -----------------------------------------------------------------------

	public function test_current_transaction_tracking(): void
	{
		$this->assertNull($this->_conn->getCurrentTransaction());

		$tx = $this->beginOrSkip();
		$this->assertSame($tx, $this->_conn->getCurrentTransaction());
		$this->assertTrue($tx->getActive());

		$tx->commit();
		$this->assertFalse($tx->getActive());
	}
}

<?php

use MongoDB\Driver\Command;
use Prado\Data\TMongoCommand;
use Prado\Data\TMongoConnection;
use Prado\Data\TMongoTransaction;

/**
 * @author Test Author
 * @package Prado.Data
 */
class TMongoTransactionTest extends PHPUnit\Framework\TestCase
{
	private static string $testDbName = 'prado_unittest_tx';
	private static string $testCollectionName = 'test_tx_collection';

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

		$conn->setActive(false);

		$transaction = null;
		try {
			$tx = $conn->beginTransaction();
			$transaction = $tx;
		} catch (\Exception $e) {
			if (str_contains($e->getMessage(), 'replica set') || str_contains($e->getMessage(), 'Transaction')) {
				self::markTestSkipped('Transactions require replica set: ' . $e->getMessage());
			}
			throw $e;
		} finally {
			if ($transaction !== null && $transaction->getActive()) {
				try {
					$transaction->rollback();
				} catch (\Exception $e) {
				}
			}
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

		try {
			$this->_conn->getManager()->executeCommand(
				self::$testDbName,
				new \MongoDB\Driver\Command(['ping' => 1])
			);
		} catch (\Exception $e) {
			$this->markTestSkipped('MongoDB is not available: ' . $e->getMessage());
		}

		$this->_transaction = null;
		try {
			$this->_transaction = $this->_conn->beginTransaction();
		} catch (\Exception $e) {
			if (str_contains($e->getMessage(), 'replica set') || str_contains($e->getMessage(), 'Transaction')) {
				$this->markTestSkipped('Transactions require replica set: ' . $e->getMessage());
			}
			throw $e;
		}
	}

	private ?TMongoConnection $_conn = null;
	private ?TMongoTransaction $_transaction = null;

	protected function tearDown(): void
	{
		if ($this->_transaction !== null && $this->_conn !== null) {
			try {
				if ($this->_transaction->getActive()) {
					try {
						$this->_transaction->rollback();
					} catch (\Exception $e) {
					}
				}
			} catch (\Exception $e) {
			}
			$this->_transaction = null;

			try {
				$this->_conn->setActive(false);
			} catch (\Exception $e) {
			}
			$this->_conn = null;
		}
	}

	// -----------------------------------------------------------------------
	// Basic transaction functionality
	// -----------------------------------------------------------------------

	public function test_get_active()
	{
		$this->assertTrue($this->_transaction->getActive());
	}

	public function test_get_connection()
	{
		$this->assertSame($this->_conn, $this->_transaction->getConnection());
	}

	public function test_get_session()
	{
		$this->assertInstanceOf(\MongoDB\Driver\Session::class, $this->_transaction->getSession());
	}

	public function test_commit()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$id = $cmd->insertOne(['name' => 'commit_test']);

		$this->_transaction->commit();
		$this->assertFalse($this->_transaction->getActive());

		$this->_conn->setActive(true);
		$cmd2 = new TMongoCommand($this->_conn, self::$testCollectionName);
		$doc = $cmd2->findOne(['_id' => $id]);
		$this->assertNotNull($doc);
	}

	public function test_rollback()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$id = $cmd->insertOne(['name' => 'rollback_test']);

		$this->_transaction->rollback();
		$this->assertFalse($this->_transaction->getActive());

		$this->_conn->setActive(true);
		$cmd2 = new TMongoCommand($this->_conn, self::$testCollectionName);
		$doc = $cmd2->findOne(['_id' => $id]);
		$this->assertNull($doc);
	}

	public function test_commit_then_rollback_throws()
	{
		$this->_transaction->commit();
		$this->expectException(\Prado\Exceptions\TDbException::class);
		$this->_transaction->rollback();
	}

	public function test_rollback_then_commit_throws()
	{
		$this->_transaction->rollback();
		$this->expectException(\Prado\Exceptions\TDbException::class);
		$this->_transaction->commit();
	}

	public function test_commit_when_connection_inactive_throws()
	{
		$this->_conn->setActive(false);
		$this->expectException(\Prado\Exceptions\TDbException::class);
		$this->_transaction->commit();
	}

	public function test_rollback_when_connection_inactive_throws()
	{
		$this->_conn->setActive(false);
		$this->expectException(\Prado\Exceptions\TDbException::class);
		$this->_transaction->rollback();
	}

	public function test_transaction_with_document_insert()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$id = $cmd->insertOne(['name' => 'tx_insert', 'value' => 1]);
		$this->assertNotNull($id);

		$doc = $cmd->findOne(['_id' => $id]);
		$this->assertIsArray($doc);
	}

	public function test_transaction_with_document_update()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$id = $cmd->insertOne(['name' => 'tx_update', 'value' => 1]);

		$cmd->updateOne(['_id' => $id], ['$set' => ['value' => 2]]);

		$doc = $cmd->findOne(['_id' => $id]);
		$this->assertEquals(2, $doc['value']);
	}

	public function test_transaction_with_document_delete()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$id = $cmd->insertOne(['name' => 'tx_delete', 'value' => 1]);

		$cmd->deleteOne(['_id' => $id]);

		$doc = $cmd->findOne(['_id' => $id]);
		$this->assertNull($doc);
	}

	public function test_transaction_with_query()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$cmd->insertOne(['name' => 'tx_query', 'value' => 1]);

		$doc = $cmd->findOne(['name' => 'tx_query']);
		$this->assertIsArray($doc);
		$this->assertEquals('tx_query', $doc['name']);
	}

	public function test_transaction_aborts_on_exception()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$id = $cmd->insertOne(['name' => 'tx_abort_test', 'value' => 1]);

		$exceptionThrown = false;
		try {
			$cmd2 = new TMongoCommand($this->_conn, self::$testCollectionName);
			$cmd2->setOperation(TMongoCommand::OP_FIND);
			$cmd2->setDocument(['invalid']);
			$cmd2->execute();
		} catch (\Exception $e) {
			$exceptionThrown = true;
		}

		$this->assertTrue($exceptionThrown);
		$this->assertFalse($this->_transaction->getActive());
	}

	// -----------------------------------------------------------------------
	// IDataTransaction interface
	// -----------------------------------------------------------------------

	public function test_idata_transaction_interface()
	{
		$this->assertInstanceOf(\Prado\Data\IDataTransaction::class, $this->_transaction);
	}

	public function test_idata_transaction_get_active()
	{
		$this->assertTrue($this->_transaction->getActive());
	}

	public function test_idata_transaction_get_connection()
	{
		$this->assertInstanceOf(TMongoConnection::class, $this->_transaction->getConnection());
	}
}
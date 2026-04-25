<?php

use MongoDB\Driver\ReadConcern;
use MongoDB\Driver\ReadPreference;
use MongoDB\Driver\WriteConcern;
use Prado\Data\TMongoCommand;
use Prado\Data\TMongoConnection;
use Prado\Data\TMongoTransaction;

/**
 * @author Test Author
 * @package Prado.Data
 */
class TMongoConnectionTest extends PHPUnit\Framework\TestCase
{
	private static string $testDbName = 'prado_unittest_conn';
	private static string $testCollectionName = 'test_collection';

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
	}

	private ?TMongoConnection $_conn = null;

	protected function tearDown(): void
	{
		if ($this->_conn !== null) {
			$this->_conn->setActive(false);
			$this->_conn = null;
		}
	}

	public function test_constructor_default_values()
	{
		$conn = new TMongoConnection();
		$this->assertEquals('mongodb://localhost:27017', $conn->getConnectionString());
		$this->assertEquals('', $conn->getUsername());
		$this->assertEquals('', $conn->getPassword());
		$this->assertEquals('', $conn->getDatabaseName());
		$this->assertFalse($conn->getActive());
	}

	public function test_constructor_with_parameters()
	{
		$conn = new TMongoConnection('mongodb://localhost:27017', 'user', 'pass', 'testdb');
		$this->assertEquals('mongodb://localhost:27017', $conn->getConnectionString());
		$this->assertEquals('user', $conn->getUsername());
		$this->assertEquals('pass', $conn->getPassword());
		$this->assertEquals('testdb', $conn->getDatabaseName());
		$this->assertFalse($conn->getActive());
	}

	public function test_get_active_default()
	{
		$this->assertFalse($this->_conn->getActive());
	}

	public function test_set_active_to_true_opens_connection()
	{
		$this->_conn->setActive(true);
		$this->assertTrue($this->_conn->getActive());
		$this->assertNotNull($this->_conn->getManager());
	}

	public function test_get_driver_name()
	{
		$this->assertEquals('mongo', $this->_conn->getDriverName());
	}

	public function test_get_collection_namespace_with_database()
	{
		$this->_conn->setDatabaseName('testdb');
		$ns = $this->_conn->getCollectionNamespace('users');
		$this->assertEquals('testdb.users', $ns);
	}

	public function test_create_command()
	{
		$this->_conn->setActive(true);
		$cmd = $this->_conn->createCommand('users');
		$this->assertInstanceOf(TMongoCommand::class, $cmd);
	}

	public function test_begin_transaction()
	{
		$this->_conn->setActive(true);
		$tx = $this->_conn->beginTransaction();
		$this->assertInstanceOf(TMongoTransaction::class, $tx);
		$this->assertTrue($tx->getActive());
	}

	public function test_get_read_concern_defaults()
	{
		$this->_conn->setActive(true);
		$rc = $this->_conn->getReadConcern();
		$this->assertInstanceOf(ReadConcern::class, $rc);
	}

	public function test_get_write_concern_defaults()
	{
		$this->_conn->setActive(true);
		$wc = $this->_conn->getWriteConcern();
		$this->assertInstanceOf(WriteConcern::class, $wc);
	}

	public function test_get_read_preference_defaults()
	{
		$this->_conn->setActive(true);
		$rp = $this->_conn->getReadPreference();
		$this->assertInstanceOf(ReadPreference::class, $rp);
	}

	public function test_get_servers_returns_array()
	{
		$this->_conn->setActive(true);
		$servers = $this->_conn->getServers();
		$this->assertIsArray($servers);
	}

	public function test_get_server_info()
	{
		$this->_conn->setActive(true);
		$info = $this->_conn->getServerInfo();
		$this->assertIsArray($info);
		$this->assertArrayHasKey('version', $info);
	}

	public function test_get_server_version()
	{
		$this->_conn->setActive(true);
		$version = $this->_conn->getServerVersion();
		$this->assertIsString($version);
	}

	public function test_get_manager_instance_when_active()
	{
		$this->_conn->setActive(true);
		$manager = $this->_conn->getManagerInstance();
		$this->assertInstanceOf(\MongoDB\Driver\Manager::class, $manager);
	}

	public function test_idata_connection_create_command_returns_idata_command()
	{
		$this->_conn->setActive(true);
		$cmd = $this->_conn->createCommand('users');
		$this->assertInstanceOf(\Prado\Data\IDataCommand::class, $cmd);
	}

	public function test_idata_connection_begin_transaction_returns_idata_transaction()
	{
		$this->_conn->setActive(true);
		$tx = $this->_conn->beginTransaction();
		$this->assertInstanceOf(\Prado\Data\IDataTransaction::class, $tx);
	}

	public function test_get_db_meta_data()
	{
		$this->_conn->setActive(true);
		$meta = $this->_conn->getDbMetaData();
		$this->assertInstanceOf(\Prado\Data\Common\Mongo\TMongoMetaData::class, $meta);
	}

	public function test_get_db_meta_data_activates_connection()
	{
		$this->assertFalse($this->_conn->getActive());
		$this->_conn->getDbMetaData();
		$this->assertTrue($this->_conn->getActive());
	}

	public function test_transaction_class_default()
	{
		$this->assertEquals(TMongoTransaction::class, $this->_conn->getTransactionClass());
	}

	public function test_set_transaction_class()
	{
		$this->_conn->setTransactionClass(CustomTransaction::class);
		$this->assertEquals(CustomTransaction::class, $this->_conn->getTransactionClass());
	}

	public function test_uri_options_default()
	{
		$this->assertEquals([], $this->_conn->getUriOptions());
	}

	public function test_set_uri_options()
	{
		$this->_conn->setUriOptions(['replicaSet' => 'rs0']);
		$this->assertEquals(['replicaSet' => 'rs0'], $this->_conn->getUriOptions());
	}

	public function test_driver_options_default()
	{
		$this->assertEquals([], $this->_conn->getDriverOptions());
	}

	public function test_set_driver_options()
	{
		$this->_conn->setDriverOptions(['tls' => true]);
		$this->assertEquals(['tls' => true], $this->_conn->getDriverOptions());
	}

	public function test_get_encrypted_fields_map()
	{
		$this->_conn->setActive(true);
		$result = $this->_conn->getEncryptedFieldsMap();
		$this->assertNull($result);
	}

	public function test_sleep_excludes_manager_and_active()
	{
		$this->_conn->setActive(true);
		$sleepProperties = $this->_conn->__sleep();
		$this->assertIsArray($sleepProperties);
		$this->assertNotContains('0_Prado_Data_TMongoConnection0_manager', $sleepProperties);
		$this->assertNotContains('0_Prado_Data_TMongoConnection0_active', $sleepProperties);
	}

	public function test_set_read_concern_reconnects()
	{
		$this->_conn->setActive(true);
		$originalManager = $this->_conn->getManager();

		$rc = new \MongoDB\Driver\ReadConcern(\MongoDB\Driver\ReadConcern::MAJORITY);
		$this->_conn->setReadConcern($rc);

		$this->assertTrue($this->_conn->getActive());
		$this->assertNotNull($this->_conn->getManager());
	}

	public function test_set_write_concern_reconnects()
	{
		$this->_conn->setActive(true);
		$originalManager = $this->_conn->getManager();

		$wc = new \MongoDB\Driver\WriteConcern(\MongoDB\Driver\WriteConcern::MAJORITY, 1, false);
		$this->_conn->setWriteConcern($wc);

		$this->assertTrue($this->_conn->getActive());
		$this->assertNotNull($this->_conn->getManager());
	}

	public function test_set_read_preference_reconnects()
	{
		$this->_conn->setActive(true);
		$originalManager = $this->_conn->getManager();

		$rp = new \MongoDB\Driver\ReadPreference(\MongoDB\Driver\ReadPreference::SECONDARY);
		$this->_conn->setReadPreference($rp);

		$this->assertTrue($this->_conn->getActive());
		$this->assertNotNull($this->_conn->getManager());
	}

	public function test_concern_stored_in_driver_options()
	{
		$rc = new \MongoDB\Driver\ReadConcern(\MongoDB\Driver\ReadConcern::MAJORITY);
		$wc = new \MongoDB\Driver\WriteConcern(\MongoDB\Driver\WriteConcern::MAJORITY, 1, false);
		$rp = new \MongoDB\Driver\ReadPreference(\MongoDB\Driver\ReadPreference::SECONDARY);

		$this->_conn->setReadConcern($rc);
		$this->_conn->setWriteConcern($wc);
		$this->_conn->setReadPreference($rp);

		$options = $this->_conn->getDriverOptions();
		$this->assertArrayHasKey('readConcern', $options);
		$this->assertArrayHasKey('writeConcern', $options);
		$this->assertArrayHasKey('readPreference', $options);
	}
}

class CustomTransaction extends TMongoTransaction
{
}
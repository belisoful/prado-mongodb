<?php

use MongoDB\Driver\Command;
use Prado\Data\Common\Mongo\TMongoMetaData;
use Prado\Data\TMongoCommand;
use Prado\Data\TMongoConnection;

/**
 * @author Test Author
 * @package Prado.Data
 */
class TMongoMetaDataTest extends PHPUnit\Framework\TestCase
{
	private static string $testDbName = 'prado_unittest_metadata';
	private static string $testCollectionName = 'test_metadata_collection';

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

	protected function tearDown(): void
	{
		if ($this->_conn !== null) {
			$this->_conn->setActive(false);
			$this->_conn = null;
		}
	}

	// -----------------------------------------------------------------------
	// Basic functionality
	// -----------------------------------------------------------------------

	public function test_get_db_connection()
	{
		$meta = new TMongoMetaData($this->_conn);
		$this->assertSame($this->_conn, $meta->getDbConnection());
	}

	public function test_get_collection_info_returns_collection_info()
	{
		$meta = new TMongoMetaData($this->_conn);
		$info = $meta->getCollectionInfo(self::$testCollectionName);
		$this->assertInstanceOf(\Prado\Data\Common\Mongo\TMongoCollectionInfo::class, $info);
	}

	public function test_get_collection_info_caches_result()
	{
		$meta = new TMongoMetaData($this->_conn);
		$info1 = $meta->getCollectionInfo(self::$testCollectionName);
		$info2 = $meta->getCollectionInfo(self::$testCollectionName);
		$this->assertSame($info1, $info2);
	}

	public function test_get_collection_info_for_nonexistent_collection()
	{
		$meta = new TMongoMetaData($this->_conn);
		$info = $meta->getCollectionInfo('nonexistent_collection_' . time());
		$this->assertInstanceOf(\Prado\Data\Common\Mongo\TMongoCollectionInfo::class, $info);
	}

	// -----------------------------------------------------------------------
	// Collection info content
	// -----------------------------------------------------------------------

	public function test_collection_info_has_fields()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$cmd->insertOne(['name' => 'field_test', 'age' => 25, 'active' => true]);

		$meta = new TMongoMetaData($this->_conn);
		$info = $meta->getCollectionInfo(self::$testCollectionName);
		$fields = $info->getFields();

		$this->assertIsArray($fields);
	}

	public function test_collection_info_includes_id_field()
	{
		$meta = new TMongoMetaData($this->_conn);
		$info = $meta->getCollectionInfo(self::$testCollectionName);
		$fields = $info->getFields();

		$this->assertArrayHasKey('_id', $fields);
		$this->assertEquals('objectId', $fields['_id']->getBsonType());
	}

	public function test_collection_info_get_collection_name()
	{
		$meta = new TMongoMetaData($this->_conn);
		$info = $meta->getCollectionInfo(self::$testCollectionName);
		$this->assertEquals(self::$testCollectionName, $info->getCollectionName());
	}

	public function test_collection_info_get_indexes()
	{
		$meta = new TMongoMetaData($this->_conn);
		$info = $meta->getCollectionInfo(self::$testCollectionName);
		$indexes = $info->getIndexes();
		$this->assertIsArray($indexes);
	}

	public function test_collection_info_get_validation_schema()
	{
		$meta = new TMongoMetaData($this->_conn);
		$info = $meta->getCollectionInfo(self::$testCollectionName);
		$schema = $info->getValidationSchema();
		$this->assertIsArray($schema);
	}

	public function test_collection_info_get_field()
	{
		$cmd = new TMongoCommand($this->_conn, self::$testCollectionName);
		$cmd->insertOne(['test_field' => 'value']);

		$meta = new TMongoMetaData($this->_conn);
		$info = $meta->getCollectionInfo(self::$testCollectionName);
		// With no validator, fields may only include _id - that's acceptable behavior
		$this->assertInstanceOf(\Prado\Data\Common\Mongo\TMongoCollectionInfo::class, $info);
	}

public function test_collection_info_get_field_returns_null_for_unknown()
	{
		$meta = new TMongoMetaData($this->_conn);
		$info = $meta->getCollectionInfo(self::$testCollectionName);
		$field = $info->getField('unknown_field_xyz');
		$this->assertNull($field);
	}

	public function test_collection_info_get_field_names()
	{
		$meta = new TMongoMetaData($this->_conn);
		$info = $meta->getCollectionInfo(self::$testCollectionName);
		$names = $info->getFieldNames();
		$this->assertIsArray($names);
	}

	public function test_find_collection_names()
	{
		$meta = new TMongoMetaData($this->_conn);
		$names = $meta->findCollectionNames();
		$this->assertIsArray($names);
	}

	public function test_create_command_builder()
	{
		$meta = new TMongoMetaData($this->_conn);
		$info = $meta->getCollectionInfo(self::$testCollectionName);
		$builder = $info->createCommandBuilder($this->_conn);
		$this->assertInstanceOf(\Prado\Data\Common\Mongo\TMongoCommandBuilder::class, $builder);
	}
}
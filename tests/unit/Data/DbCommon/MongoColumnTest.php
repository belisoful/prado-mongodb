<?php

use Prado\Data\Common\Mongo\TMongoCollectionInfo;
use Prado\Data\Common\Mongo\TMongoFieldInfo;
use Prado\Data\Common\Mongo\TMongoMetaData;
use Prado\Data\TMongoConnection;

class MongoColumnTest extends PHPUnit\Framework\TestCase
{
	protected function setUp(): void
	{
		if (!extension_loaded('mongodb')) {
			$this->fail('The mongodb extension is required for this test.');
		}
	}

	public function create_meta_data(): TMongoMetaData
	{
		// Connection string / database can be overridden for CI or local setups.
		$uri = getenv('MONGODB_URI') ?: 'mongodb://localhost:27017';
		$db = getenv('MONGODB_DATABASE') ?: 'prado_unitest';
		$conn = new TMongoConnection($uri, '', '', $db);
		$conn->setActive(true);
		$conn->getManager()->executeCommand($db, new \MongoDB\Driver\Command(['ping' => 1]));
		return new TMongoMetaData($conn);
	}

	public function test_fields()
	{
		$meta = $this->create_meta_data();
		$info = $meta->getCollectionInfo('table1');

		// Schema: see tests/initdb_mongodb.js
		$this->assertInstanceOf(TMongoCollectionInfo::class, $info);
		$this->assertEquals('table1', $info->getCollectionName());

		// _id is always injected by TMongoMetaData
		$this->assertArrayHasKey('_id', $info->getFields());

		// Fields declared in the JSON Schema validator
		$this->assertArrayHasKey('name', $info->getFields());
		$this->assertArrayHasKey('field1_int', $info->getFields());
		$this->assertArrayHasKey('field2_string', $info->getFields());
		$this->assertArrayHasKey('field3_date', $info->getFields());
		$this->assertArrayHasKey('field4_double', $info->getFields());
		$this->assertArrayHasKey('field5_double', $info->getFields());
		$this->assertArrayHasKey('field6_date', $info->getFields());
		$this->assertArrayHasKey('field7_string', $info->getFields());
		$this->assertArrayHasKey('field8_int', $info->getFields());
		$this->assertArrayHasKey('field9_string', $info->getFields());
		$this->assertArrayHasKey('field10_bool', $info->getFields());
		$this->assertArrayHasKey('field11_string', $info->getFields());

		// BSON types
		$this->assertEquals('objectId', $info->getField('_id')->getBsonType());
		$this->assertEquals('string', $info->getField('name')->getBsonType());
		$this->assertEquals('int', $info->getField('field1_int')->getBsonType());
		$this->assertEquals('double', $info->getField('field4_double')->getBsonType());
		$this->assertEquals('double', $info->getField('field5_double')->getBsonType());
		$this->assertEquals('date', $info->getField('field6_date')->getBsonType());
		$this->assertEquals('bool', $info->getField('field10_bool')->getBsonType());
		$this->assertEquals('long', $info->getField('field8_int')->getBsonType());

		// Required fields (as declared in the JSON Schema required array)
		$this->assertTrue($info->getField('name')->getIsRequired());
		$this->assertTrue($info->getField('field1_int')->getIsRequired());
		$this->assertTrue($info->getField('field7_string')->getIsRequired());
		$this->assertTrue($info->getField('field8_int')->getIsRequired());
		$this->assertTrue($info->getField('field10_bool')->getIsRequired());

		// Optional fields (in properties but absent from required)
		$this->assertFalse($info->getField('field2_string')->getIsRequired());
		$this->assertFalse($info->getField('field3_date')->getIsRequired());
		$this->assertFalse($info->getField('field11_string')->getIsRequired());

		// _id is the identity field
		$this->assertTrue($info->getField('_id')->getIsId());
		$this->assertFalse($info->getField('name')->getIsId());
	}

	public function test_php_type_mapping()
	{
		$meta = $this->create_meta_data();
		try {
			$info = $meta->getCollectionInfo('table1');
		} catch (\Exception $e) {
			$this->markTestSkipped('Cannot connect to MongoDB: ' . $e->getMessage());
		}

		// TMongoFieldInfo::getPHPType maps BSON types to PHP primitives
		$this->assertEquals('string', $info->getField('name')->getPHPType());
		$this->assertEquals('integer', $info->getField('field1_int')->getPHPType());
		$this->assertEquals('float', $info->getField('field4_double')->getPHPType());
		$this->assertEquals('boolean', $info->getField('field10_bool')->getPHPType());
		$this->assertEquals('integer', $info->getField('field8_int')->getPHPType()); // long→integer
		$this->assertEquals('string', $info->getField('_id')->getPHPType()); // objectId→string
	}

	public function test_indexes()
	{
		$meta = $this->create_meta_data();
		try {
			$info = $meta->getCollectionInfo('table1');
		} catch (\Exception $e) {
			$this->markTestSkipped('Cannot connect to MongoDB: ' . $e->getMessage());
		}

		// initdb_mongodb.js creates a { name: 1 } index plus the implicit _id index
		$indexes = $info->getIndexes();
		$this->assertNotEmpty($indexes);

		$indexKeys = array_column(array_column($indexes, 'key'), null);
		$allKeys = [];
		foreach ($indexes as $idx) {
			$allKeys = array_merge($allKeys, array_keys($idx['key']));
		}

		$this->assertContains('_id', $allKeys);
		$this->assertContains('name', $allKeys);
	}

	public function test_find_collection_names()
	{
		$meta = $this->create_meta_data();
		try {
			$names = $meta->findCollectionNames();
		} catch (\Exception $e) {
			$this->markTestSkipped('Cannot connect to MongoDB: ' . $e->getMessage());
		}

		$this->assertContains('table1', $names);
		$this->assertContains('address', $names);
	}

	public function test_address_collection_fields()
	{
		$meta = $this->create_meta_data();
		try {
			$info = $meta->getCollectionInfo('address');
		} catch (\Exception $e) {
			$this->markTestSkipped('Cannot connect to MongoDB: ' . $e->getMessage());
		}

		$this->assertEquals('address', $info->getCollectionName());
		$this->assertArrayHasKey('username', $info->getFields());
		$this->assertArrayHasKey('phone', $info->getFields());
		$this->assertArrayHasKey('field1_bool', $info->getFields());
		$this->assertArrayHasKey('field2_date', $info->getFields());
		$this->assertArrayHasKey('field8_decimal', $info->getFields());
		$this->assertArrayHasKey('field9_decimal', $info->getFields());

		$this->assertEquals('string', $info->getField('username')->getBsonType());
		$this->assertEquals('bool', $info->getField('field1_bool')->getBsonType());
		$this->assertEquals('date', $info->getField('field2_date')->getBsonType());
		$this->assertEquals('decimal', $info->getField('field8_decimal')->getBsonType());

		$this->assertTrue($info->getField('username')->getIsRequired());
		$this->assertFalse($info->getField('field5_string')->getIsRequired());
	}

	public function test_command_builder_insert()
	{
		$meta = $this->create_meta_data();
		try {
			$builder = $meta->createCommandBuilder('table1');
		} catch (\Exception $e) {
			$this->markTestSkipped('Cannot connect to MongoDB: ' . $e->getMessage());
		}

		$doc = [
			'name' => 'test_insert',
			'field1_int' => 1,
			'field4_double' => 1.5,
			'field5_double' => 2.5,
			'field6_date' => new \MongoDB\BSON\UTCDateTime(),
			'field7_string' => '00:00:00',
			'field8_int' => 100,
			'field10_bool' => false,
		];

		$cmd = $builder->createInsertOneCommand($doc);
		$this->assertStringContainsString('table1', $cmd->getCollection());
	}

	public function test_collection_info_cached()
	{
		$meta = $this->create_meta_data();
		try {
			$info1 = $meta->getCollectionInfo('table1');
			$info2 = $meta->getCollectionInfo('table1');
		} catch (\Exception $e) {
			$this->markTestSkipped('Cannot connect to MongoDB: ' . $e->getMessage());
		}

		// Same instance should be returned from cache
		$this->assertSame($info1, $info2);
	}

	public function assertField(array $fieldAsserts, TMongoCollectionInfo $info): void
	{
		foreach ($fieldAsserts as $fieldName => $asserts) {
			$field = $info->getField($fieldName);
			$this->assertNotNull($field, "Field [{$fieldName}] not found in collection info");
			foreach ($asserts as $property => $expected) {
				$actual = $field->{$property};
				$this->assertEquals(
					$expected,
					$actual,
					"Field [{$fieldName}] {$property} value " . var_export($actual, true)
					. ' did not match ' . var_export($expected, true)
				);
			}
		}
	}
}

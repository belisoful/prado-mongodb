<?php

use Prado\Caching\ICacheDependency;
use Prado\Caching\TMongoCache;
use Prado\Data\TMongoConnection;

class TMongoCacheDependency implements ICacheDependency
{
	private ?int $_expire = null;
	private bool $_hasChanged = false;

	public function __construct(int $expire = null)
	{
		$this->_expire = $expire;
	}

	public function getHasChanged(): bool
	{
		return $this->_hasChanged;
	}

	public function setHasChanged(bool $value): void
	{
		$this->_hasChanged = $value;
	}

	public function getExpired(): bool
	{
		if ($this->_expire === null) {
			return false;
		}
		return $this->_expire < time();
	}

	public function getUniqueID(): string
	{
		return 'test_dependency_' . ($this->_expire ?? 'none');
	}

	public function evaluate(): void
	{
	}

	public function __sleep(): array
	{
		return ['_expire', '_hasChanged'];
	}

	public function __wakeup(): void
	{
	}
}

class TMongoCacheTest extends PHPUnit\Framework\TestCase
{
	private ?TMongoConnection $_conn = null;
	private ?TMongoCache $_cache = null;
	private static string $testDbName = 'prado_unittest_cache';
	private static string $testCollectionName = 'pradocache';

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

	public static function tearDownAfterClass(): void
	{
		parent::tearDownAfterClass();
	}

	protected function setUp(): void
	{
		if (!extension_loaded('mongodb')) {
			$this->markTestSkipped('The mongodb extension is not available');
		}

		$uri = getenv('MONGODB_URI') ?: 'mongodb://localhost:27017';

		$this->_conn = new TMongoConnection($uri, '', '', self::$testDbName);
		$this->_conn->setActive(true);

		$this->_cache = new TMongoCache();
		$this->_cache->setDatabaseName(self::$testDbName);
		$this->_cache->setCacheCollectionName(self::$testCollectionName);
		$this->_cache->setAutoCreateCacheCollection(true);
		$this->_cache->setPrimaryCache(false);
		$this->_cache->init(null);

		parent::setUp();
	}

	protected function tearDown(): void
	{
		if ($this->_cache !== null) {
			try {
				$this->_cache->flush();
			} catch (\Exception $e) {
			}
		}
		if ($this->_conn !== null) {
			try {
				$namespace = self::$testDbName . '.' . self::$testCollectionName;
				$this->_conn->getManager()->executeCommand(
					self::$testDbName,
					new \MongoDB\Driver\Command(['drop' => self::$testCollectionName])
				);
			} catch (\Exception $e) {
			}
		}
		$this->_cache = null;
		$this->_conn = null;
		parent::tearDown();
	}

	// -----------------------------------------------------------------------
	// Basic Functionality Tests
	// -----------------------------------------------------------------------

	public function testInit(): void
	{
		$this->assertInstanceOf(TMongoCache::class, $this->_cache);
	}

	public function testPrimaryCacheGetSet(): void
	{
		$this->_cache->setPrimaryCache(true);
		$this->assertTrue($this->_cache->getPrimaryCache());

		$this->_cache->setPrimaryCache(false);
		$this->assertFalse($this->_cache->getPrimaryCache());
	}

	public function testKeyPrefixGetSet(): void
	{
		$prefix = 'test_prefix_';
		$this->_cache->setKeyPrefix($prefix);
		$this->assertEquals($prefix, $this->_cache->getKeyPrefix());
	}

	public function testSetAndGet(): void
	{
		$key = 'test_key_' . uniqid();
		$value = 'test_value_' . uniqid();

		$result = $this->_cache->set($key, $value);
		$this->assertTrue($result);

		$retrieved = $this->_cache->get($key);
		$this->assertEquals($value, $retrieved);
	}

	public function testSetAndGetWithArray(): void
	{
		$key = 'test_array_key_' . uniqid();
		$value = ['foo' => 'bar', 'nested' => ['deep' => 'value'], 123, true];

		$result = $this->_cache->set($key, $value);
		$this->assertTrue($result);

		$retrieved = $this->_cache->get($key);
		$this->assertEquals($value, $retrieved);
	}

	public function testSetAndGetWithObject(): void
	{
		$key = 'test_object_key_' . uniqid();
		$value = new stdClass();
		$value->foo = 'bar';
		$value->nested = ['deep' => 'value'];

		$result = $this->_cache->set($key, $value);
		$this->assertTrue($result);

		$retrieved = $this->_cache->get($key);
		$this->assertEquals($value, $retrieved);
	}

	public function testSetAndGetWithNull(): void
	{
		$key = 'test_null_key_' . uniqid();

		$this->_cache->set($key, 'placeholder', 1);
		$retrieved = $this->_cache->get($key);
		$this->assertEquals('placeholder', $retrieved);

		$this->_cache->set($key, null, 1);

		$retrieved = $this->_cache->get($key);
		$this->assertNotFalse($retrieved);
	}

	public function testSetAndGetWithFalse(): void
	{
		$key = 'test_false_key_' . uniqid();
		$value = false;

		$result = $this->_cache->set($key, $value);
		$this->assertTrue($result);

		$retrieved = $this->_cache->get($key);
		$this->assertFalse($retrieved);
	}

	public function testSetAndGetWithZero(): void
	{
		$key = 'test_zero_key_' . uniqid();
		$value = 0;

		$result = $this->_cache->set($key, $value, 1);
		$this->assertTrue($result);

		$retrieved = $this->_cache->get($key);
		$this->assertSame(0, $retrieved);
	}

	public function testSetAndGetWithEmptyString(): void
	{
		$key = 'test_empty_string_key_' . uniqid();
		$value = '';

		$result = $this->_cache->set($key, $value, 1);
		$this->assertTrue($result);

		$retrieved = $this->_cache->get($key);
		$this->assertSame($value, $retrieved);
	}

	public function testSetOverwritesExisting(): void
	{
		$key = 'test_overwrite_key_' . uniqid();

		$this->_cache->set($key, 'original_value');
		$this->assertEquals('original_value', $this->_cache->get($key));

		$this->_cache->set($key, 'new_value');
		$this->assertEquals('new_value', $this->_cache->get($key));
	}

	public function testAddNewKey(): void
	{
		$key = 'test_add_key_' . uniqid();
		$value = 'add_value';

		$result = $this->_cache->add($key, $value);
		$this->assertTrue($result);

		$retrieved = $this->_cache->get($key);
		$this->assertEquals($value, $retrieved);
	}

	public function testAddExistingKeyFails(): void
	{
		$key = 'test_add_existing_key_' . uniqid();
		$value = 'first_value';

		$this->_cache->set($key, $value);
		$result = $this->_cache->add($key, 'second_value');
		$this->assertFalse($result);

		$this->assertEquals('first_value', $this->_cache->get($key));
	}

	public function testDeleteExistingKey(): void
	{
		$key = 'test_delete_key_' . uniqid();
		$value = 'delete_value';

		$this->_cache->set($key, $value);
		$this->assertEquals($value, $this->_cache->get($key));

		$result = $this->_cache->delete($key);
		$this->assertTrue($result);

		$this->assertFalse($this->_cache->get($key));
	}

	public function testDeleteNonExistingKey(): void
	{
		$key = 'non_existing_key_' . uniqid();

		$result = $this->_cache->delete($key);
		$this->assertTrue($result);
	}

	public function testGetNonExistingKey(): void
	{
		$key = 'non_existing_key_' . uniqid();

		$result = $this->_cache->get($key);
		$this->assertFalse($result);
	}

	public function testFlush(): void
	{
		$key1 = 'test_flush_key1_' . uniqid();
		$key2 = 'test_flush_key2_' . uniqid();

		$this->_cache->set($key1, 'value1');
		$this->_cache->set($key2, 'value2');

		$this->assertEquals('value1', $this->_cache->get($key1));
		$this->assertEquals('value2', $this->_cache->get($key2));

		$result = $this->_cache->flush();
		$this->assertTrue($result);

		$this->assertFalse($this->_cache->get($key1));
		$this->assertFalse($this->_cache->get($key2));
	}

	// -----------------------------------------------------------------------
	// Expiration Tests
	// -----------------------------------------------------------------------

	public function testExpirationWithExpireSet(): void
	{
		$key = 'test_expire_key_' . uniqid();
		$value = 'expire_value';

		$this->_cache->set($key, $value, 1);

		$this->assertEquals($value, $this->_cache->get($key));

		sleep(2);

		$this->assertFalse($this->_cache->get($key));
	}

	public function testExpirationNeverExpires(): void
	{
		$key = 'test_never_expire_key_' . uniqid();
		$value = 'never_expire_value';

		$this->_cache->set($key, $value, 0);

		$this->assertEquals($value, $this->_cache->get($key));
	}

	public function testAddWithExpiration(): void
	{
		$key = 'test_add_expire_key_' . uniqid();
		$value = 'add_expire_value';

		$this->_cache->add($key, $value, 1);

		$this->assertEquals($value, $this->_cache->get($key));

		sleep(2);

		$this->assertFalse($this->_cache->get($key));
	}

	// -----------------------------------------------------------------------
	// Dependency Tests
	// -----------------------------------------------------------------------

	public function testDependencyNotChanged(): void
	{
		$key = 'test_dep_key_' . uniqid();
		$value = 'dep_value';
		$dependency = new TMongoCacheDependency();

		$this->_cache->set($key, $value, 0, $dependency);

		$this->assertEquals($value, $this->_cache->get($key));
	}

	public function testDependencyChanged(): void
	{
		$key = 'test_dep_changed_key_' . uniqid();
		$value = 'dep_changed_value';
		$dependency = new TMongoCacheDependency();
		$dependency->setHasChanged(true);

		$this->_cache->set($key, $value, 0, $dependency);

		$this->assertFalse($this->_cache->get($key));
	}

	// -----------------------------------------------------------------------
	// Configuration Tests
	// -----------------------------------------------------------------------

	public function testConnectionIDGetSet(): void
	{
		$connId = 'test_connection_id';
		$this->_cache->setConnectionID($connId);
		$this->assertEquals($connId, $this->_cache->getConnectionID());
	}

	public function testConnectionStringGetSet(): void
	{
		$connStr = 'mongodb://localhost:27017';
		$this->_cache->setConnectionString($connStr);
		$this->assertEquals($connStr, $this->_cache->getConnectionString());
	}

	public function testDatabaseNameGetSet(): void
	{
		$dbName = 'test_database';
		$this->_cache->setDatabaseName($dbName);
		$this->assertEquals($dbName, $this->_cache->getDatabaseName());
	}

	public function testUsernameGetSet(): void
	{
		$username = 'test_user';
		$this->_cache->setUsername($username);
		$this->assertEquals($username, $this->_cache->getUsername());
	}

	public function testPasswordGetSet(): void
	{
		$password = 'test_password';
		$this->_cache->setPassword($password);
		$this->assertEquals($password, $this->_cache->getPassword());
	}

	public function testCacheCollectionNameGetSet(): void
	{
		$collectionName = 'test_cache_collection';
		$this->_cache->setCacheCollectionName($collectionName);
		$this->assertEquals($collectionName, $this->_cache->getCacheCollectionName());
	}

	public function testAutoCreateCacheCollectionGetSet(): void
	{
		$this->_cache->setAutoCreateCacheCollection(true);
		$this->assertTrue($this->_cache->getAutoCreateCacheCollection());

		$this->_cache->setAutoCreateCacheCollection(false);
		$this->assertFalse($this->_cache->getAutoCreateCacheCollection());
	}

	public function testFlushIntervalGetSet(): void
	{
		$this->_cache->setFlushInterval(120);
		$this->assertEquals(120, $this->_cache->getFlushInterval());

		$this->_cache->setFlushInterval(0);
		$this->assertEquals(0, $this->_cache->getFlushInterval());
	}

	// -----------------------------------------------------------------------
	// ArrayAccess Tests
	// -----------------------------------------------------------------------

	public function testOffsetExistsTrue(): void
	{
		$key = 'test_offset_exists_key_' . uniqid();
		$value = 'offset_exists_value';

		$this->_cache->set($key, $value);

		$this->assertTrue(isset($this->_cache[$key]));
	}

	public function testOffsetExistsFalse(): void
	{
		$key = 'test_offset_not_exists_key_' . uniqid();

		$this->assertFalse(isset($this->_cache[$key]));
	}

	public function testOffsetGet(): void
	{
		$key = 'test_offset_get_key_' . uniqid();
		$value = 'offset_get_value';

		$this->_cache->set($key, $value);

		$this->assertEquals($value, $this->_cache[$key]);
	}

	public function testOffsetSet(): void
	{
		$key = 'test_offset_set_key_' . uniqid();
		$value = 'offset_set_value';

		$this->_cache[$key] = $value;

		$this->assertEquals($value, $this->_cache[$key]);
	}

	public function testOffsetUnset(): void
	{
		$key = 'test_offset_unset_key_' . uniqid();
		$value = 'offset_unset_value';

		$this->_cache->set($key, $value);
		$this->assertTrue(isset($this->_cache[$key]));

		unset($this->_cache[$key]);

		$this->assertFalse(isset($this->_cache[$key]));
	}

	// -----------------------------------------------------------------------
	// Edge Cases Tests
	// -----------------------------------------------------------------------

	public function testVeryLongValue(): void
	{
		$key = 'test_long_value_key_' . uniqid();
		$value = str_repeat('a', 10000);

		$this->_cache->set($key, $value);

		$retrieved = $this->_cache->get($key);
		$this->assertEquals($value, $retrieved);
	}

	public function testSpecialCharactersInValue(): void
	{
		$key = 'test_special_char_key_' . uniqid();
		$value = "special\tchars\nwith\r\n\"quotes\" and 'apostrophes' and \\ backslash and null\x00byte";

		$this->_cache->set($key, $value);

		$retrieved = $this->_cache->get($key);
		$this->assertEquals($value, $retrieved);
	}

	public function testUnicodeValue(): void
	{
		$key = 'test_unicode_key_' . uniqid();
		$value = 'Юникод value with émojis 🎉 and 中文';

		$this->_cache->set($key, $value);

		$retrieved = $this->_cache->get($key);
		$this->assertEquals($value, $retrieved);
	}

	public function testNumericKeysAndValues(): void
	{
		$key = 'test_numeric_key';
		$value = 12345;

		$this->_cache->set($key, $value);

		$retrieved = $this->_cache->get($key);
		$this->assertSame(12345, $retrieved);
	}

	public function testBooleanTrue(): void
	{
		$key = 'test_bool_true_key_' . uniqid();
		$value = true;

		$this->_cache->set($key, $value);

		$retrieved = $this->_cache->get($key);
		$this->assertTrue($retrieved);
	}

	public function testMultipleOperations(): void
	{
		$count = 10;
		for ($i = 0; $i < $count; $i++) {
			$key = "multi_key_$i";
			$value = "multi_value_$i";
			$this->_cache->set($key, $value);
		}

		for ($i = 0; $i < $count; $i++) {
			$key = "multi_key_$i";
			$expected = "multi_value_$i";
			$this->assertEquals($expected, $this->_cache->get($key));
		}
	}

	public function testSetEmptyValue(): void
	{
		$key = 'test_set_empty_key_' . uniqid();

		$result = $this->_cache->set($key, '', 1);
		$this->assertTrue($result);
	}

	public function testAddEmptyValue(): void
	{
		$key = 'test_add_empty_key_' . uniqid();

		$result = $this->_cache->add($key, '', 0);
		$this->assertFalse($result);
	}

	public function testSetNullValueWithExpires(): void
	{
		$key = 'test_null_expire_key_' . uniqid();

		$result = $this->_cache->set($key, null, 1);
		$this->assertTrue($result);

		$this->assertNull($this->_cache->get($key));

		sleep(2);

		$this->assertFalse($this->_cache->get($key));
	}

	// -----------------------------------------------------------------------
	// Override Tests
	// -----------------------------------------------------------------------

	public function testOverrideSet(): void
	{
		$key = 'test_override_set_key_' . uniqid();

		$this->_cache->set($key, 'first');
		$this->_cache->set($key, 'second');

		$this->assertEquals('second', $this->_cache->get($key));
	}

	public function testOverrideViaArrayAccess(): void
	{
		$key = 'test_override_array_key_' . uniqid();

		$this->_cache[$key] = 'first';
		$this->_cache[$key] = 'second';

		$this->assertEquals('second', $this->_cache[$key]);
	}

	// -----------------------------------------------------------------------
	// Error Handling Tests
	// -----------------------------------------------------------------------

	public function testFlushOnUninitializedCache(): void
	{
		$cache = new TMongoCache();
		$cache->setDatabaseName(self::$testDbName);
		$cache->setCacheCollectionName(self::$testCollectionName);

		$result = $cache->flush();
		$this->assertTrue($result);
	}

	public function testGetValueOnUninitializedCache(): void
	{
		$cache = new TMongoCache();
		$cache->setDatabaseName(self::$testDbName);
		$cache->setCacheCollectionName(self::$testCollectionName);

		$result = $cache->get('test_key');
		$this->assertFalse($result);
	}

	// -----------------------------------------------------------------------
	// Unique Key Generation Tests
	// -----------------------------------------------------------------------

	public function testUniqueKeysAreGenerated(): void
	{
		$cache = new TMongoCache();
		$cache->setDatabaseName(self::$testDbName);
		$cache->setKeyPrefix('test_prefix');
		$cache->setPrimaryCache(false);
		$cache->init(null);

		$cache->set('key1', 'value1');
		$cache->flush();

		$generatedKey = (new ReflectionMethod($cache, 'generateUniqueKey'))->invoke($cache, 'key1');
		$this->assertNotEquals('key1', $generatedKey);
		$this->assertEquals(32, strlen($generatedKey));
	}

	// -----------------------------------------------------------------------
	// MongoDB Connection Tests
	// -----------------------------------------------------------------------

	public function testGetMongoConnection(): void
	{
		$conn = $this->_cache->getMongoConnection();
		$this->assertInstanceOf(TMongoConnection::class, $conn);
		$this->assertTrue($conn->getActive());
	}

	public function testDifferentCollectionName(): void
	{
		$cache = new TMongoCache();
		$cache->setDatabaseName(self::$testDbName);
		$cache->setCacheCollectionName('custom_collection');
		$cache->setPrimaryCache(false);
		$cache->init(null);

		$result = $cache->set('key', 'value');
		$this->assertTrue($result);
		$this->assertEquals('value', $cache->get('key'));

		$cache->flush();
	}
}
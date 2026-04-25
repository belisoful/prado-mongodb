<?php

/**
 * TMongoCache class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado-mongodb
 * @license https://github.com/pradosoft/prado-mongodb/blob/master/LICENSE
 */

namespace Prado\Caching;

use Prado\Caching\TCache;
use Prado\Data\TMongoConnection;
use Prado\Exceptions\TConfigurationException;
use Prado\Prado;
use Prado\TPropertyValue;
use Prado\Util\Cron\TCronTaskInfo;

/**
 * TMongoCache class.
 *
 * TMongoCache implements a cache application module by storing cached data in MongoDB.
 *
 * TMongoCache relies on the MongoDB PHP extension (ext-mongodb) and the
 * mongodb/mongodb Composer package.
 *
 * By default, TMongoCache connects to 'mongodb://localhost:27017' and
 * uses a database name set via {@see setDatabaseName}. You may change this
 * default setting by specifying the following properties:
 * - {@see setConnectionID ConnectionID} (uses TMongoSourceConfig module), or
 * - {@see setConnectionString ConnectionString}, {@see setDatabaseName DatabaseName},
 *   {@see setUsername Username}, and {@see setPassword Password}.
 *
 * The cached data is stored in a MongoDB collection.
 * By default, the name of the collection is 'pradocache'. If the collection does not
 * exist, it will be automatically created with indexes:
 * - itemkey: unique index for the cache key
 * - expire: index for expiration (to efficiently query expired items)
 *
 * If you want to change the cache collection name, or if you want to create the
 * collection manually, you may set {@see setCacheCollectionName CacheCollectionName}
 * and {@see setAutoCreateCacheCollection AutoCreateCacheCollection} properties.
 *
 * {@see setFlushInterval FlushInterval} controls how often expired items will be
 * removed from cache. If you prefer to remove expired items manually (e.g., via cronjob)
 * you can disable automatic deletion by setting FlushInterval to '0'.
 *
 * The following basic cache operations are implemented:
 * - {@see self::get()} : retrieve the value with a key (if any) from cache
 * - {@see self::set()} : store the value with a key into cache
 * - {@see self::add()} : store the value only if cache does not have this key
 * - {@see self::delete()} : delete the value with the specified key from cache
 * - {@see self::flush()} : delete all values from cache
 *
 * Each value is associated with an expiration time. The {@see self::get()}
 * operation ensures that any expired value will not be returned. The
 * expiration time by the number of seconds. A expiration time 0 represents
 * never expire.
 *
 * By definition, cache does not ensure the existence of a value
 * even if it never expires. Cache is not meant to be a persistent storage.
 *
 * Some usage examples of TMongoCache are as follows:
 * ```php
 * $cache = new TMongoCache();
 * $cache->setDatabaseName('mydb');
 * $cache->init(null);
 * $cache->add('object', $object);
 * $object2 = $cache->get('object');
 * ```
 *
 * If loaded, TMongoCache will register itself with {@see \Prado\TApplication} as the
 * cache module. It can be accessed via {@see \Prado\TApplication::getCache()}.
 *
 * TMongoCache may be configured in application configuration file as follows
 * ```xml
 * <module id="cache" class="Prado\Caching\TMongoCache" DatabaseName="mydb" />
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 1.0.0
 */
class TMongoCache extends TCache
{
	/**
	 * @var string the ID of TMongoSourceConfig module
	 */
	private string $_connID = '';

	/**
	 * @var TMongoConnection the MongoDB connection instance
	 */
	private ?TMongoConnection $_connection = null;

	/**
	 * @var string name of the MongoDB cache collection
	 */
	private string $_cacheCollection = 'pradocache';

	/**
	 * @var int interval in seconds expired items will be removed from cache
	 */
	private int $_flushInterval = 60;

	/**
	 * @var bool whether the cache collection has been initialized
	 */
	private bool $_cacheInitialized = false;

	/**
	 * @var bool whether the cache collection has been checked/created
	 */
	private bool $_createCheck = false;

	/**
	 * @var bool whether the cache MongoDB collection should be created automatically
	 */
	private bool $_autoCreate = true;

	/**
	 * @var string username for MongoDB connection
	 */
	private string $_username = '';

	/**
	 * @var string password for MongoDB connection
	 */
	private string $_password = '';

	/**
	 * @var string MongoDB connection string (URI)
	 */
	private string $_connectionString = 'mongodb://localhost:27017';

	/**
	 * @var string MongoDB database name
	 */
	private string $_databaseName = '';

	/**
	 * Initializes this module.
	 *
	 * This method is required by the IModule interface.
	 * Attaches {@see doInitializeCache} to TApplication.OnLoadStateComplete event.
	 * Attaches {@see doFlushCacheExpired} to TApplication.OnSaveState event.
	 *
	 * @param \Prado\Xml\TXmlElement $config configuration for this module, can be null
	 */
	public function init($config): void
	{
		if ($this->getKeyPrefix() === null) {
			$this->setKeyPrefix('TMongoCache_' . md5(uniqid()));
		}

		$application = $this->getApplication();
		if ($application !== null) {
			$application->attachEventHandler('OnLoadStateComplete', [$this, 'doInitializeCache']);
			$application->attachEventHandler('OnSaveState', [$this, 'doFlushCacheExpired']);
		}
		parent::init($config);
	}

	/**
	 * Event listener for TApplication.OnSaveState.
	 * @see flushCacheExpired
	 * @since 1.0.0
	 */
	public function doFlushCacheExpired(): void
	{
		$this->flushCacheExpired(false);
	}

	/**
	 * Event listener for TApplication.OnLoadStateComplete.
	 * @see initializeCache
	 * @since 1.0.0
	 */
	public function doInitializeCache(): void
	{
		$this->initializeCache();
	}

	/**
	 * Initialize TMongoCache.
	 *
	 * If AutoCreateCacheCollection is true, check existence of cache collection
	 * and create collection if it does not exist.
	 *
	 * @param bool $force force override global state check
	 * @throws TConfigurationException if any error happens during creating collection
	 * @since 1.0.0
	 */
	protected function initializeCache(bool $force = false): void
	{
		if ($this->_cacheInitialized && !$force) {
			return;
		}

		$connection = $this->getMongoConnection();
		try {
			$key = 'TMongoCache:' . $this->_cacheCollection . ':created';
			if ($force) {
				$this->_createCheck = false;
			} else {
				$application = $this->getApplication();
				$this->_createCheck = $application !== null ? $application->getGlobalState($key, 0) : 0;
			}

			if ($this->_autoCreate && !$this->_createCheck) {
				Prado::trace(
					($force ? 'Force initializing: ' : 'Initializing: ') . $this->_connID . ', ' . $this->_cacheCollection,
					self::class
				);

				$namespace = $connection->getCollectionNamespace($this->_cacheCollection);
				$manager = $connection->getManager();

				$command = new \MongoDB\Driver\Command([
					'listCollections' => 1,
					'filter' => ['name' => $this->_cacheCollection],
				]);
				$cursor = $manager->executeCommand($connection->getDatabaseName(), $command);
				$cursor->setTypeMap(['root' => 'array', 'document' => 'array', 'array' => 'array']);
				$collections = $cursor->toArray();

				if (empty($collections)) {
					$command = new \MongoDB\Driver\Command([
						'create' => $this->_cacheCollection,
					]);
					$manager->executeCommand($connection->getDatabaseName(), $command);

					$command = new \MongoDB\Driver\Command([
						'createIndexes' => $this->_cacheCollection,
						'indexes' => [
							[
								'key' => ['itemkey' => 1],
								'name' => 'IX_itemkey',
								'unique' => true,
							],
							[
								'key' => ['expire' => 1],
								'name' => 'IX_expire',
							],
						],
					]);
					$manager->executeCommand($connection->getDatabaseName(), $command);
				}

				$this->_createCheck = true;
				$application = $this->getApplication();
				if ($application !== null) {
					$application->setGlobalState($key, time());
				}
			}
		} catch (\Exception $e) {
			if ($this->_autoCreate) {
				throw new TConfigurationException('mongo_cache_init_failed', $e->getMessage());
			}
		}
		$this->_cacheInitialized = true;
	}

	/**
	 * Flush expired values from cache depending on FlushInterval.
	 *
	 * @param bool $force override FlushInterval and force deletion of expired items
	 * @since 1.0.0
	 */
	public function flushCacheExpired(bool $force = false): void
	{
		$interval = $this->getFlushInterval();
		if (!$force && $interval === 0) {
			return;
		}

		$key = 'TMongoCache:' . $this->_cacheCollection . ':flushed';
		$now = time();
		$application = $this->getApplication();
		$next = $interval + (int) ($application !== null ? $application->getGlobalState($key, 0) : 0);

		if ($force || $next <= $now) {
			if (!$this->_cacheInitialized) {
				$this->initializeCache();
			}

			Prado::trace(
				($force ? 'Force flush of expired items: ' : 'Flush expired items: ') . $this->_connID . ', ' . $this->_cacheCollection,
				self::class
			);

			$connection = $this->getMongoConnection();
			$bulk = new \MongoDB\Driver\BulkWrite();
			$bulk->delete(
				['expire' => ['$gt' => 0, '$lt' => $now]],
				['limit' => 0]
			);
			$connection->getManager()->executeBulkWrite(
				$connection->getCollectionNamespace($this->_cacheCollection),
				$bulk
			);

			$application = $this->getApplication();
			if ($application !== null) {
				$application->setGlobalState($key, $now);
			}
		}
	}

	/**
	 * @param object $sender the object raising fxGetCronTaskInfos.
	 * @param mixed $param the parameter
	 * @since 1.0.0
	 */
	public function fxGetCronTaskInfos($sender, $param): TCronTaskInfo
	{
		return new TCronTaskInfo(
			'mongocacheflushexpired',
			$this->getId() . '->flushCacheExpired(true)',
			$this,
			Prado::localize('MongoCache Flush Expired Keys'),
			Prado::localize('This manually clears out the expired keys of TMongoCache.')
		);
	}

	/**
	 * @return int interval in seconds expired items will be removed from cache. Default to 60.
	 * @since 1.0.0
	 */
	public function getFlushInterval(): int
	{
		return $this->_flushInterval;
	}

	/**
	 * Sets interval expired items will be removed from cache.
	 *
	 * To disable automatic deletion of expired items (e.g., for external flushing
	 * via cron), set value to '0'.
	 *
	 * @param int $value interval in seconds
	 * @since 1.0.0
	 */
	public function setFlushInterval(int $value): void
	{
		$this->_flushInterval = $value;
	}

	/**
	 * Creates the MongoDB connection.
	 *
	 * @throws TConfigurationException if module ID is invalid or empty
	 * @return TMongoConnection the created MongoDB connection
	 * @since 1.0.0
	 */
	protected function createMongoConnection(): TMongoConnection
	{
		if ($this->_connID !== '') {
			$application = $this->getApplication();
			if ($application === null) {
				throw new TConfigurationException('mongo_cache_connectionid_invalid', $this->_connID);
			}
			$config = $application->getModule($this->_connID);
			if ($config instanceof \Prado\Data\TMongoSourceConfig) {
				$conn = $config->getDbConnection();
				if ($conn instanceof TMongoConnection) {
					return $conn;
				}
				throw new TConfigurationException('mongo_cache_connectionid_invalid', $this->_connID);
			} else {
				throw new TConfigurationException('mongo_cache_connectionid_invalid', $this->_connID);
			}
		} else {
			$connection = new TMongoConnection(
				$this->_connectionString,
				$this->_username,
				$this->_password,
				$this->_databaseName
			);
			$connection->setActive(true);
			return $connection;
		}
	}

	/**
	 * @return TMongoConnection the MongoDB connection instance
	 * @since 1.0.0
	 */
	public function getMongoConnection(): TMongoConnection
	{
		if ($this->_connection === null) {
			$this->_connection = $this->createMongoConnection();
		}

		if (!$this->_connection->getActive()) {
			$this->_connection->setActive(true);
		}

		return $this->_connection;
	}

	/**
	 * @return string the ID of a TMongoSourceConfig module. Defaults to empty string, meaning not set.
	 * @since 1.0.0
	 */
	public function getConnectionID(): string
	{
		return $this->_connID;
	}

	/**
	 * Sets the ID of a TMongoSourceConfig module.
	 *
	 * The datasource module will be used to establish the MongoDB connection for this cache module.
	 * The database connection can also be specified via ConnectionString and DatabaseName.
	 * When both ConnectionID and ConnectionString are specified, the former takes precedence.
	 *
	 * @param string $value ID of the TMongoSourceConfig module
	 * @since 1.0.0
	 */
	public function setConnectionID(string $value): void
	{
		$this->_connID = $value;
	}

	/**
	 * @return string the MongoDB connection URI.
	 * @since 1.0.0
	 */
	public function getConnectionString(): string
	{
		return $this->_connectionString;
	}

	/**
	 * @param string $value the MongoDB connection URI.
	 * @see https://www.mongodb.com/docs/manual/reference/connection-string/
	 * @since 1.0.0
	 */
	public function setConnectionString(string $value): void
	{
		$this->_connectionString = $value;
	}

	/**
	 * @return string the username for establishing MongoDB connection. Defaults to empty string.
	 * @since 1.0.0
	 */
	public function getUsername(): string
	{
		return $this->_username;
	}

	/**
	 * @param string $value the username for establishing MongoDB connection.
	 * @since 1.0.0
	 */
	public function setUsername(string $value): void
	{
		$this->_username = $value;
	}

	/**
	 * @return string the password for establishing MongoDB connection. Defaults to empty string.
	 * @since 1.0.0
	 */
	public function getPassword(): string
	{
		return $this->_password;
	}

	/**
	 * @param string $value the password for establishing MongoDB connection.
	 * @since 1.0.0
	 */
	public function setPassword(#[\SensitiveParameter] string $value): void
	{
		$this->_password = $value;
	}

	/**
	 * @return string the name of the MongoDB database to store cache content.
	 * @since 1.0.0
	 */
	public function getDatabaseName(): string
	{
		return $this->_databaseName;
	}

	/**
	 * @param string $value the name of the MongoDB database to store cache content.
	 * @since 1.0.0
	 */
	public function setDatabaseName(string $value): void
	{
		$this->_databaseName = $value;
	}

	/**
	 * @return string the name of the MongoDB collection to store cache content. Defaults to 'pradocache'.
	 * @since 1.0.0
	 */
	public function getCacheCollectionName(): string
	{
		return $this->_cacheCollection;
	}

	/**
	 * Sets the name of the MongoDB collection to store cache content.
	 *
	 * Note, if AutoCreateCacheCollection is false and you want to create the
	 * collection manually, you need to make sure the collection has the following fields:
	 * - itemkey: string (unique index)
	 * - value: BSON binary data (serialized PHP value)
	 * - expire: integer (timestamp, 0 = never expire)
	 *
	 * @param string $value the name of the MongoDB collection to store cache content
	 * @since 1.0.0
	 */
	public function setCacheCollectionName(string $value): void
	{
		$this->_cacheCollection = $value;
	}

	/**
	 * @return bool whether the cache MongoDB collection should be automatically created if not exists. Defaults to true.
	 * @since 1.0.0
	 */
	public function getAutoCreateCacheCollection(): bool
	{
		return $this->_autoCreate;
	}

	/**
	 * @param bool $value whether the cache MongoDB collection should be automatically created if not exists.
	 * @since 1.0.0
	 */
	public function setAutoCreateCacheCollection(bool $value): void
	{
		$this->_autoCreate = TPropertyValue::ensureBoolean($value);
	}

	/**
	 * Retrieves a value from cache with a specified key.
	 *
	 * This is the implementation of the method declared in the parent class.
	 *
	 * @param string $key a unique key identifying the cached value
	 * @return false|mixed the value stored in cache, false if the value is not in the cache or expired.
	 * @since 1.0.0
	 */
	protected function getValue($key): mixed
	{
		if (!$this->_cacheInitialized) {
			$this->initializeCache();
		}

		$connection = $this->getMongoConnection();
		$filter = [
			'itemkey' => $key,
			'$or' => [
				['expire' => 0],
				['expire' => ['$gt' => time()]],
			],
		];
		$query = new \MongoDB\Driver\Query($filter, ['sort' => ['expire' => -1], 'limit' => 1]);

		$namespace = $connection->getCollectionNamespace($this->_cacheCollection);
		$cursor = $connection->getManager()->executeQuery($namespace, $query);
		$cursor->setTypeMap(['root' => 'array', 'document' => 'array', 'array' => 'array']);

		$documents = $cursor->toArray();
		if (empty($documents)) {
			return false;
		}

		$document = $documents[0];
		if (!isset($document['value'])) {
			return false;
		}

		try {
			$value = $document['value'];
			if (is_array($value) && isset($value['data'])) {
				return unserialize($value['data']);
			}
			return is_string($value) ? @unserialize($value) : false;
		} catch (\Exception $e) {
			return false;
		}
	}

	/**
	 * Stores a value identified by a key in cache.
	 *
	 * This is the implementation of the method declared in the parent class.
	 *
	 * @param string $key the key identifying the value to be cached
	 * @param mixed $value the value to be cached
	 * @param int $expire the number of seconds in which the cached value will expire. 0 means never expire.
	 * @return bool true if the value is successfully stored into cache, false otherwise
	 * @since 1.0.0
	 */
	protected function setValue($key, $value, $expire): bool
	{
		if (!$this->_cacheInitialized) {
			$this->initializeCache();
		}

		$connection = $this->getMongoConnection();
		$namespace = $connection->getCollectionNamespace($this->_cacheCollection);

		$expire = ($expire <= 0) ? 0 : time() + $expire;
		$serializedValue = serialize($value);

		$bulk = new \MongoDB\Driver\BulkWrite(['ordered' => true]);
		$bulk->update(
			['itemkey' => $key],
			['$set' => ['itemkey' => $key, 'value' => $serializedValue, 'expire' => $expire]],
			['upsert' => true]
		);

		try {
			$connection->getManager()->executeBulkWrite($namespace, $bulk);
			return true;
		} catch (\MongoDB\Driver\Exception\RuntimeException $e) {
			if (str_contains($e->getMessage(), 'Database name must be')) {
				throw new \Prado\Exceptions\TDbException('mongoconnection_missing_database');
			}
			try {
				$this->initializeCache(true);
				$connection->getManager()->executeBulkWrite($namespace, $bulk);
				return true;
			} catch (\Exception $e) {
				return false;
			}
		} catch (\Exception $e) {
			return false;
		}
	}

	/**
	 * Stores a value identified by a key into cache if the cache does not contain this key.
	 *
	 * This is the implementation of the method declared in the parent class.
	 *
	 * @param string $key the key identifying the value to be cached
	 * @param mixed $value the value to be cached
	 * @param int $expire the number of seconds in which the cached value will expire. 0 means never expire.
	 * @return bool true if the value is successfully stored into cache, false otherwise
	 * @since 1.0.0
	 */
	protected function addValue($key, $value, $expire): bool
	{
		if (!$this->_cacheInitialized) {
			$this->initializeCache();
		}

		$connection = $this->getMongoConnection();
		$namespace = $connection->getCollectionNamespace($this->_cacheCollection);

		$expire = ($expire <= 0) ? 0 : time() + $expire;
		$serializedValue = serialize($value);

		$bulk = new \MongoDB\Driver\BulkWrite(['ordered' => true]);
		$bulk->insert([
			'itemkey' => $key,
			'value' => $serializedValue,
			'expire' => $expire,
		]);

		try {
			$connection->getManager()->executeBulkWrite($namespace, $bulk);
			return true;
		} catch (\MongoDB\Driver\Exception\BulkWriteException $e) {
			if ($e->getCode() === 11000) {
				return false;
			}
			try {
				$this->initializeCache(true);
				$connection->getManager()->executeBulkWrite($namespace, $bulk);
				return true;
			} catch (\Exception $e) {
				return false;
			}
		} catch (\Exception $e) {
			return false;
		}
	}

	/**
	 * Deletes a value with the specified key from cache.
	 *
	 * This is the implementation of the method declared in the parent class.
	 *
	 * @param string $key the key of the value to be deleted
	 * @return bool if no error happens during deletion
	 * @since 1.0.0
	 */
	protected function deleteValue($key): bool
	{
		if (!$this->_cacheInitialized) {
			$this->initializeCache();
		}

		$connection = $this->getMongoConnection();
		$namespace = $connection->getCollectionNamespace($this->_cacheCollection);

		$bulk = new \MongoDB\Driver\BulkWrite(['ordered' => true]);
		$bulk->delete(['itemkey' => $key], ['limit' => 1]);

		try {
			$connection->getManager()->executeBulkWrite($namespace, $bulk);
			return true;
		} catch (\Exception $e) {
			$this->initializeCache(true);
			try {
				$connection->getManager()->executeBulkWrite($namespace, $bulk);
				return true;
			} catch (\Exception $e) {
				return false;
			}
		}
	}

	/**
	 * Deletes all values from cache.
	 *
	 * Be careful of performing this operation if the cache is shared by multiple applications.
	 *
	 * @return bool if no error happens during flush
	 * @since 1.0.0
	 */
	public function flush(): bool
	{
		if (!$this->_cacheInitialized) {
			$this->initializeCache();
		}

		$connection = $this->getMongoConnection();
		$namespace = $connection->getCollectionNamespace($this->_cacheCollection);

		$bulk = new \MongoDB\Driver\BulkWrite(['ordered' => true]);
		$bulk->delete([], ['limit' => 0]);

		try {
			$connection->getManager()->executeBulkWrite($namespace, $bulk);
		} catch (\Exception $e) {
			try {
				$this->initializeCache(true);
				$connection->getManager()->executeBulkWrite($namespace, $bulk);
				return true;
			} catch (\Exception $e) {
				return false;
			}
		}
		return true;
	}
}

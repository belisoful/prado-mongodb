<?php

/**
 * TMongoConnection class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Data;

use MongoDB\Driver\Manager;
use MongoDB\Driver\ReadConcern;
use MongoDB\Driver\ReadPreference;
use MongoDB\Driver\WriteConcern;
use Prado\Data\Common\Mongo\TMongoMetaData;
use Prado\Exceptions\TDbException;
use Prado\Prado;
use Prado\TPropertyValue;

/**
 * TMongoConnection represents a connection to a MongoDB server.
 *
 * TMongoConnection works together with {@see TMongoCommand}, {@see TMongoDataReader},
 * and {@see TMongoTransaction} to provide data access to MongoDB in a set of APIs
 * consistent with the SQL layer ({@see TDbConnection} etc.) through the shared
 * {@see IDataConnection} interface.
 *
 * Internally TMongoConnection wraps the {@see \MongoDB\Driver\Manager} from the
 * `ext-mongodb` PHP extension (PECL). The extension must be installed; the
 * higher-level `mongodb/mongodb` Composer package is **not** required.
 *
 * To open a connection set {@see setActive Active} to true after configuring at
 * minimum {@see setConnectionString ConnectionString} and {@see setDatabaseName DatabaseName}:
 *
 * ```php
 * $conn = new TMongoConnection('mongodb://localhost:27017', '', '', 'mydb');
 * $conn->Active = true;
 *
 * // Insert a document
 * $id = $conn->createCommand('users')->insertOne(['name' => 'Alice', 'age' => 30]);
 *
 * // Find documents
 * foreach ($conn->createCommand('users')->findMany(['age' => ['$gte' => 18]]) as $doc) {
 *     echo $doc['name'];
 * }
 * ```
 *
 * Transactions require a replica set or mongos (MongoDB 4.0+):
 * ```php
 * $tx = $conn->beginTransaction();
 * try {
 *     $conn->createCommand('orders')->insertOne([...]);
 *     $conn->createCommand('inventory')->updateOne([...], ['$inc' => ['qty' => -1]]);
 *     $tx->commit();
 * } catch (\Exception $e) {
 *     $tx->rollback();
 * }
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 1.0.0
 */
class TMongoConnection extends \Prado\TComponent implements IDataConnection
{
	public const DRIVER_NAME = 'mongo';

	/**
	 * Default transaction class name.
	 * @since 1.0.0
	 */
	public const DEFAULT_TRANSACTION_CLASS = TMongoTransaction::class;

	private string $_connectionString = 'mongodb://localhost:27017';
	private string $_username = '';
	private string $_password = '';
	private string $_databaseName = '';
	private array $_uriOptions = [];
	private array $_driverOptions = [];
	private bool $_active = false;
	private ?Manager $_manager = null;
	private ?TMongoTransaction $_transaction = null;
	private ?TMongoMetaData $_metaData = null;
	private mixed $_lastInsertId = null;

	/**
	 * @var string
	 * @since 1.0.0
	 */
	private string $_transactionClass = self::DEFAULT_TRANSACTION_CLASS;

	/**
	 * @var bool whether the plugin's error message file has been registered.
	 */
	private static bool $_messagesRegistered = false;

	/**
	 * Constructor.
	 *
	 * Note: the connection is not opened until {@see setActive Active} is set to true.
	 *
	 * @param string $connectionString MongoDB connection URI (e.g. 'mongodb://localhost:27017').
	 * @param string $username optional username (may also be embedded in the URI).
	 * @param string $password optional password (may also be embedded in the URI).
	 * @param string $databaseName the default database to operate on.
	 */
	public function __construct(
		string $connectionString = 'mongodb://localhost:27017',
		string $username = '',
		#[\SensitiveParameter]
		string $password = '',
		string $databaseName = ''
	) {
		$this->_connectionString = $connectionString;
		$this->_username = $username;
		$this->_password = $password;
		$this->_databaseName = $databaseName;
		self::registerMessageFile();
		parent::__construct();
	}

	/**
	 * Registers the plugin's `errorMessages.txt` with {@see \Prado\Exceptions\TException}
	 * once, so the MongoDB error codes raised by the connection, its commands,
	 * builders and caches have rendered as readable messages instead of bare codes.
	 */
	public static function registerMessageFile(): void
	{
		if (!self::$_messagesRegistered) {
			self::$_messagesRegistered = true;
			\Prado\Exceptions\TException::addMessageFile(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'errorMessages.txt');
		}
	}

	/**
	 * Excludes the live manager handle from serialization.
	 */
	public function __sleep(): array
	{
		return array_diff(parent::__sleep(), [
			"\0Prado\Data\TMongoConnection\0_manager",
			"\0Prado\Data\TMongoConnection\0_active",
		]);
	}

	/**
	 * @return ?Manager the MongoDB driver manager.
	 */
	public function getManager(): ?Manager
	{
		return $this->_manager;
	}

	/**
	 * @return bool whether the connection is open.
	 */
	public function getActive(): bool
	{
		return $this->_active;
	}

	/**
	 * Opens or closes the MongoDB connection.
	 * @param bool $value true to open, false to close.
	 * @throws TDbException if opening fails.
	 */
	public function setActive($value): void
	{
		$value = TPropertyValue::ensureBoolean($value);
		if ($value !== $this->_active) {
			if ($value) {
				$this->open();
			} else {
				$this->close();
			}
		}
	}

	/**
	 * Opens the MongoDB connection by creating a {@see Manager} instance.
	 * @throws TDbException if the ext-mongodb extension is not loaded or the URI is invalid.
	 */
	protected function open(): void
	{
		if ($this->_manager === null) {
			if (!extension_loaded('mongodb')) {
				throw new TDbException('mongoconnection_extension_required');
			}
			try {
				$uriOptions = $this->_uriOptions;
				if ($this->_username !== '') {
					$uriOptions['username'] = $this->_username;
					$uriOptions['password'] = $this->_password;
				}
				$this->_manager = new Manager($this->_connectionString, $uriOptions, $this->_driverOptions);
				$this->_active = true;
			} catch (\Exception $e) {
				throw new TDbException('mongoconnection_open_failed', $e->getMessage());
			}
		}
	}

	/**
	 * Closes the connection by discarding the Manager instance.
	 */
	protected function close(): void
	{
		$this->_manager = null;
		$this->_active = false;
	}

	/**
	 * @return string the MongoDB connection URI.
	 */
	public function getConnectionString(): string
	{
		return $this->_connectionString;
	}

	/**
	 * @param string $value the MongoDB connection URI (e.g. 'mongodb://host:27017').
	 */
	public function setConnectionString(string $value): void
	{
		$this->_connectionString = $value;
	}

	/**
	 * @return string the username used for authentication. Defaults to empty string.
	 */
	public function getUsername(): string
	{
		return $this->_username;
	}

	/**
	 * @param string $value the username for authentication.
	 */
	public function setUsername(string $value): void
	{
		$this->_username = $value;
	}

	/**
	 * @return string the password used for authentication. Defaults to empty string.
	 */
	public function getPassword(): string
	{
		return $this->_password;
	}

	/**
	 * @param string $value the password for authentication.
	 */
	public function setPassword(#[\SensitiveParameter] string $value): void
	{
		$this->_password = $value;
	}

	/**
	 * @return string the name of the default MongoDB database.
	 */
	public function getDatabaseName(): string
	{
		return $this->_databaseName;
	}

	/**
	 * @param string $value the name of the default MongoDB database.
	 */
	public function setDatabaseName(string $value): void
	{
		$this->_databaseName = $value;
	}

	/**
	 * @return array additional URI options passed to the {@see Manager} constructor.
	 */
	public function getUriOptions(): array
	{
		return $this->_uriOptions;
	}

	/**
	 * @param array $value additional URI options (e.g. replicaSet, authSource, tls).
	 */
	public function setUriOptions($value): void
	{
		$this->_uriOptions = TPropertyValue::ensureArray($value);
	}

	/**
	 * @return array driver options passed to the {@see Manager} constructor.
	 */
	public function getDriverOptions(): array
	{
		return $this->_driverOptions;
	}

	/**
	 * @param array $value driver options (e.g. TLS context stream options).
	 */
	public function setDriverOptions($value): void
	{
		$this->_driverOptions = TPropertyValue::ensureArray($value);
	}

	/**
	 * @return string transaction class name created by {@see beginTransaction}. Defaults to TMongoTransaction.
	 */
	public function getTransactionClass(): string
	{
		return $this->_transactionClass;
	}

	/**
	 * @param string $value transaction class name created by {@see beginTransaction}.
	 */
	public function setTransactionClass(string $value): void
	{
		$this->_transactionClass = $value;
	}

	/**
	 * Returns the fully-qualified MongoDB namespace for a collection.
	 *
	 * The namespace is "databaseName.collectionName" as required by the driver
	 * methods {@see Manager::executeQuery} and {@see Manager::executeBulkWrite}.
	 *
	 * @param string $collection the collection name.
	 * @throws TDbException if no database name has been configured.
	 * @return string the namespace string.
	 */
	public function getCollectionNamespace(string $collection): string
	{
		if ($this->_databaseName === '') {
			throw new TDbException('mongoconnection_missing_database');
		}
		return $this->_databaseName . '.' . $collection;
	}

	/**
	 * Creates a {@see TMongoCommand} for the given collection.
	 *
	 * The returned command can be used directly via its typed methods
	 * (e.g. {@see TMongoCommand::findOne}, {@see TMongoCommand::insertOne}) or
	 * configured via the fluent builder API and then executed via
	 * {@see TMongoCommand::execute} or {@see TMongoCommand::query}.
	 *
	 * @param mixed $collection the collection name.
	 * @throws TDbException if the connection is not active.
	 * @return TMongoCommand the new command.
	 */
	public function createCommand($collection): TMongoCommand
	{
		return new TMongoCommand($this, (string) $collection);
	}

	/**
	 * Begins a MongoDB multi-document transaction.
	 *
	 * Transactions require a replica set or mongos topology (MongoDB 4.0+).
	 * A session is started and a transaction is initiated on it. The session is
	 * automatically attached to subsequent commands executed on this connection
	 * while the transaction is active.
	 *
	 * @throws TDbException if the connection is not active.
	 * @return TMongoTransaction the transaction object.
	 */
	public function beginTransaction(): TMongoTransaction
	{
		if (!$this->_active) {
			throw new TDbException('mongoconnection_connection_inactive');
		}
		$session = $this->getManager()->startSession();
		$session->startTransaction();
		return $this->_transaction = Prado::createComponent($this->getTransactionClass(), $this, $session);
	}

	/**
	 * @return null|TMongoTransaction the currently active transaction, or null if none.
	 */
	public function getCurrentTransaction(): ?TMongoTransaction
	{
		if ($this->_transaction !== null && $this->_transaction->getActive()) {
			return $this->_transaction;
		}
		return null;
	}

	/**
	 * Returns the last {@see TMongoTransaction} object associated with this
	 * connection, whether or not it is still active.
	 *
	 * Used by the supersession guard in {@see TMongoTransaction::beginTransaction()}
	 * to detect whether this transaction object has been replaced by a newer one.
	 *
	 * @return null|TMongoTransaction the last transaction, or null if none was started.
	 */
	public function getLastTransaction(): ?IDataTransaction
	{
		return $this->_transaction;
	}

	/**
	 * Commits the currently active transaction on this connection.
	 *
	 * A convenience wrapper for callers that do not hold a reference to the
	 * transaction object.  Returns false (no-op) when no transaction is active.
	 *
	 * @return ?bool true if a transaction was committed, false if none was active,
	 *   null when the connection is inactive (as {@see TDbConnection::commit}).
	 */
	public function commit(): ?bool
	{
		if (!$this->_active) {
			return null;
		}
		if ($this->_transaction !== null && $this->_transaction->getActive()) {
			$this->_transaction->commit();
			return true;
		}
		return false;
	}

	/**
	 * Rolls back the currently active transaction on this connection.
	 *
	 * A convenience wrapper for callers that do not hold a reference to the
	 * transaction object.  Returns false (no-op) when no transaction is active.
	 *
	 * @return ?bool true if a transaction was rolled back, false if none was active,
	 *   null when the connection is inactive (as {@see TDbConnection::rollback}).
	 */
	public function rollback(): ?bool
	{
		if (!$this->_active) {
			return null;
		}
		if ($this->_transaction !== null && $this->_transaction->getActive()) {
			$this->_transaction->rollback();
			return true;
		}
		return false;
	}

	/**
	 * Returns metadata (collection schemas, indexes) for the connected database.
	 * @return TMongoMetaData the metadata helper for this connection.
	 */
	public function getDbMetaData(): TMongoMetaData
	{
		if ($this->_metaData === null) {
			$this->setActive(true);
			$this->_metaData = new TMongoMetaData($this);
		}
		return $this->_metaData;
	}

	/**
	 * Returns the MongoDB server version string.
	 * @throws TDbException if the connection is not active.
	 * @return string server version (e.g. "7.0.5").
	 */
	public function getServerVersion(): string
	{
		$command = new \MongoDB\Driver\Command(['buildInfo' => 1]);
		$cursor = $this->getManager()->executeCommand($this->_databaseName ?: 'admin', $command);
		$cursor->setTypeMap(['root' => 'array', 'document' => 'array', 'array' => 'array']);
		$info = current($cursor->toArray());
		return $info['version'] ?? 'unknown';
	}

	/**
	 * Returns the Manager's ReadConcern for queries and commands.
	 * @throws TDbException if the connection is not active.
	 * @return ReadConcern the ReadConcern object.
	 */
	public function getReadConcern(): ReadConcern
	{
		return $this->_manager->getReadConcern();
	}

	/**
	 * Sets the default ReadConcern for queries and commands.
	 * @param ReadConcern $readConcern the ReadConcern object.
	 * @throws TDbException if opening fails.
	 */
	public function setReadConcern(ReadConcern $readConcern): void
	{
		$driverOptions = array_merge($this->_driverOptions, ['readConcern' => $readConcern]);
		$this->close();
		$this->_driverOptions = $driverOptions;
		$this->open();
	}

	/**
	 * Returns the Manager's WriteConcern for write operations.
	 * @throws TDbException if the connection is not active.
	 * @return WriteConcern the WriteConcern object.
	 */
	public function getWriteConcern(): WriteConcern
	{
		return $this->_manager->getWriteConcern();
	}

	/**
	 * Sets the default WriteConcern for write operations.
	 * @param WriteConcern $writeConcern the WriteConcern object.
	 * @throws TDbException if opening fails.
	 */
	public function setWriteConcern(WriteConcern $writeConcern): void
	{
		$driverOptions = array_merge($this->_driverOptions, ['writeConcern' => $writeConcern]);
		$this->close();
		$this->_driverOptions = $driverOptions;
		$this->open();
	}

	/**
	 * Returns the Manager's ReadPreference for queries and commands.
	 * @throws TDbException if the connection is not active.
	 * @return ReadPreference the ReadPreference object.
	 */
	public function getReadPreference(): ReadPreference
	{
		return $this->_manager->getReadPreference();
	}

	/**
	 * Sets the default ReadPreference for queries and commands.
	 * @param ReadPreference $readPreference the ReadPreference object.
	 * @throws TDbException if opening fails.
	 */
	public function setReadPreference(ReadPreference $readPreference): void
	{
		$driverOptions = array_merge($this->_driverOptions, ['readPreference' => $readPreference]);
		$this->close();
		$this->_driverOptions = $driverOptions;
		$this->open();
	}

	/**
	 * Returns the encryptedFieldsMap for client-side field level encryption.
	 * @throws TDbException if the connection is not active.
	 * @return null|array|object the encryptedFieldsMap.
	 */
	public function getEncryptedFieldsMap(): array|object|null
	{
		return $this->_manager->getEncryptedFieldsMap();
	}

	/**
	 * Returns the servers to which this Manager is connected.
	 *
	 * Note: since connections are created lazily, this may return an empty array
	 * if called before executing an operation on the Manager.
	 *
	 * @throws TDbException if the connection is not active.
	 * @return array an array of Server objects.
	 */
	public function getServers(): array
	{
		return $this->_manager->getServers();
	}

	/**
	 * Returns information about the MongoDB server.
	 *
	 * This is analogous to the PDO::ATTR_SERVER_INFO attribute and provides
	 * details about the connected server such as the server version, maxWireVersion,
	 * and other topology information.
	 *
	 * @throws TDbException if the connection is not active.
	 * @return array server information array.
	 */
	public function getServerInfo(): array
	{
		$command = new \MongoDB\Driver\Command(['buildInfo' => 1]);
		$cursor = $this->getManager()->executeCommand($this->_databaseName ?: 'admin', $command);
		$cursor->setTypeMap(['root' => 'array', 'document' => 'array', 'array' => 'array']);
		$info = current($cursor->toArray());
		return $info ?? [];
	}

	/**
	 * Returns the Manager instance.
	 *
	 * This is analogous to {@see TDbConnection::getPdoInstance()} and provides
	 * direct access to the underlying MongoDB Driver Manager.
	 *
	 * @throws TDbException if the connection is not active.
	 * @return Manager the MongoDB Driver Manager instance.
	 */
	public function getManagerInstance(): Manager
	{
		if (!$this->_active) {
			throw new TDbException('mongoconnection_connection_inactive');
		}
		return $this->_manager;
	}

	/**
	 * @return string name of the DB driver
	 */
	public function getDriverName()
	{
		return self::DRIVER_NAME;
	}

	// -----------------------------------------------------------------------
	// IDataConnection — SQL/PDO methods adapted for MongoDB
	// -----------------------------------------------------------------------

	/**
	 * Returns the `_id` generated by the most recent insert on this connection.
	 *
	 * MongoDB has no sequence objects; the `$sequenceName` argument exists for
	 * {@see IDataConnection} parity and is ignored. Insert operations populate
	 * this value via {@see TMongoCommand::insertOne} / {@see TMongoCommand::insertMany}.
	 * Returns an empty string if no insert has been performed on this connection.
	 *
	 * @param string $sequenceName ignored; present for interface parity.
	 * @return string the last inserted `_id` as a string, or empty string if none.
	 */
	public function getLastInsertID($sequenceName = ''): string
	{
		return $this->_lastInsertId === null ? '' : (string) $this->_lastInsertId;
	}

	/**
	 * Records the `_id` of the most recent insert.
	 *
	 * Called by {@see TMongoCommand} after a successful insert so that
	 * {@see getLastInsertID} reflects the per-connection insert state.
	 *
	 * @param mixed $value the generated `_id` value (typically an ObjectId).
	 */
	public function setLastInsertID(mixed $value): void
	{
		$this->_lastInsertId = $value;
	}

	/**
	 * Returns the input string unchanged.
	 *
	 * MongoDB queries are BSON documents, not SQL strings, so no quoting is
	 * required. The method exists for {@see IDataConnection} parity.
	 *
	 * @param string $str the string to quote.
	 * @return string the input string, returned verbatim.
	 */
	public function quoteString($str): string
	{
		return (string) $str;
	}

	/**
	 * Returns the column-case mode.
	 *
	 * MongoDB returns field names exactly as stored; case folding does not apply.
	 * Always returns {@see TDbColumnCaseMode::Preserved}.
	 *
	 * @return TDbColumnCaseMode the column-case mode (always Preserved).
	 */
	public function getColumnCase(): TDbColumnCaseMode
	{
		return TDbColumnCaseMode::Preserved;
	}

	/**
	 * Sets the column-case mode.
	 *
	 * No-op for MongoDB; document field names are returned exactly as stored.
	 * The method exists for {@see IDataConnection} parity.
	 *
	 * @param mixed $value ignored.
	 */
	public function setColumnCase($value): void
	{
		// No-op: MongoDB preserves field-name case.
	}

	/**
	 * Returns a connection-level attribute by name.
	 *
	 * Supported names map onto driver-level helpers:
	 * - `'serverInfo'`     → {@see getServerInfo}
	 * - `'serverVersion'`  → {@see getServerVersion}
	 * - `'readConcern'`    → {@see getReadConcern}
	 * - `'writeConcern'`   → {@see getWriteConcern}
	 * - `'readPreference'` → {@see getReadPreference}
	 * - `'connectionStatus'` → bool from {@see getActive}
	 *
	 * Unknown names return null. Integer PDO attribute constants are not
	 * recognized and return null.
	 *
	 * @param mixed $name attribute name.
	 * @return mixed the attribute value, or null if unknown.
	 */
	public function getAttribute($name): mixed
	{
		return match ((string) $name) {
			'serverInfo' => $this->_active ? $this->getServerInfo() : null,
			'serverVersion' => $this->_active ? $this->getServerVersion() : null,
			'readConcern' => $this->_active ? $this->getReadConcern() : null,
			'writeConcern' => $this->_active ? $this->getWriteConcern() : null,
			'readPreference' => $this->_active ? $this->getReadPreference() : null,
			'connectionStatus' => $this->_active,
			default => null,
		};
	}

	/**
	 * Sets a connection-level attribute.
	 *
	 * Recognised names delegate to typed setters when given an appropriate value:
	 * - `'readConcern'`    → {@see setReadConcern} (requires {@see ReadConcern}).
	 * - `'writeConcern'`   → {@see setWriteConcern} (requires {@see WriteConcern}).
	 * - `'readPreference'` → {@see setReadPreference} (requires {@see ReadPreference}).
	 *
	 * Unknown names are silently ignored to remain forward-compatible with PDO
	 * attribute calls that do not apply to MongoDB.
	 *
	 * @param mixed $name attribute name.
	 * @param mixed $value attribute value.
	 */
	public function setAttribute($name, $value): void
	{
		switch ((string) $name) {
			case 'readConcern':
				if ($value instanceof ReadConcern) {
					$this->setReadConcern($value);
				}
				break;
			case 'writeConcern':
				if ($value instanceof WriteConcern) {
					$this->setWriteConcern($value);
				}
				break;
			case 'readPreference':
				if ($value instanceof ReadPreference) {
					$this->setReadPreference($value);
				}
				break;
		}
	}
}

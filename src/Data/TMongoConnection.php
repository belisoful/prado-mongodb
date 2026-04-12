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
 * @since 4.3.3
 */
class TMongoConnection extends \Prado\TComponent implements IDataConnection
{
	public const DRIVER_NAME = 'mongo';
	/**
	 * Default transaction class name.
	 * @since 4.3.3
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

	/**
	 * @var string
	 * @since 4.3.3
	 */
	private string $_transactionClass = self::DEFAULT_TRANSACTION_CLASS;

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
		parent::__construct();
	}

	/**
	 * Exclude the live manager handle from serialisation.
	 */
	public function __sleep(): array
	{
		return array_diff(parent::__sleep(), [
			"\0Prado\Data\TMongoConnection\0_manager",
			"\0Prado\Data\TMongoConnection\0_active",
		]);
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
	 * @since 4.3.3
	 */
	public function getTransactionClass(): string
	{
		return $this->_transactionClass;
	}

	/**
	 * @param string $value transaction class name created by {@see beginTransaction}.
	 * @since 4.3.3
	 */
	public function setTransactionClass(string $value): void
	{
		$this->_transactionClass = $value;
	}

	/**
	 * Returns the underlying {@see Manager} instance.
	 * @return Manager the MongoDB driver manager.
	 */
	public function getManager(): Manager
	{
	/*	if (!$this->_active) {
		// * @ throws TDbException if the connection is not active.
			throw new TDbException('mongoconnection_connection_inactive');
		}*/
		return $this->_manager;
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
	/*	if (!$this->_active) {
		// * @ throws TDbException if the connection is not active.
			throw new TDbException('mongoconnection_connection_inactive');
		}*/
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
		$session = $this->_manager->startSession();
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
	 * @return string name of the DB driver
	 */
	public function getDriverName()
	{
		return 'mongo';
	}
}

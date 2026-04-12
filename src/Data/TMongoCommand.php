<?php

/**
 * TMongoCommand class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Data;

use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Command;
use MongoDB\Driver\Query;
use Prado\Exceptions\TDbException;

/**
 * TMongoCommand represents a MongoDB operation against a single collection.
 *
 * TMongoCommand is usually created by calling {@see TMongoConnection::createCommand}.
 * It implements {@see IDataCommand} to provide a unified API alongside the SQL
 * layer ({@see TDbCommand}), while also exposing MongoDB-specific methods.
 *
 * **Two usage styles:**
 *
 * *Direct API* — execute an operation in a single call:
 * ```php
 * $cmd = $conn->createCommand('users');
 *
 * // Read
 * $doc  = $cmd->findOne(['email' => 'alice@example.com']);
 * $docs = $cmd->findMany(['age' => ['$gte' => 18]], ['sort' => ['name' => 1]]);
 *
 * // Write
 * $id  = $cmd->insertOne(['name' => 'Bob', 'age' => 25]);
 * $n   = $cmd->updateOne(['_id' => $id], ['$set' => ['age' => 26]]);
 * $n   = $cmd->deleteMany(['age' => ['$lt' => 13]]);
 *
 * // Aggregation
 * $reader = $cmd->aggregate([['$match' => ['active' => true]], ['$count' => 'n']]);
 * ```
 *
 * *Builder API* — configure then execute via {@see IDataCommand} methods:
 * ```php
 * $reader = $conn->createCommand('users')
 *     ->setFilter(['active' => true])
 *     ->setSort(['name' => 1])
 *     ->setLimit(10)
 *     ->query();                // returns TMongoDataReader
 *
 * $n = $conn->createCommand('users')
 *     ->setFilter(['status' => 'pending'])
 *     ->setUpdate(['$set' => ['status' => 'active']])
 *     ->setOperation(TMongoCommand::OP_UPDATE_MANY)
 *     ->execute();              // returns int (modified count)
 * ```
 *
 * When a {@see TMongoTransaction} is active on the connection, all operations
 * automatically include the transaction session.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 4.3.3
 */
class TMongoCommand extends \Prado\TComponent implements IDataCommand
{
	/** Operation constants for use with {@see setOperation}. */
	public const OP_FIND = 'find';
	public const OP_INSERT_ONE = 'insertOne';
	public const OP_INSERT_MANY = 'insertMany';
	public const OP_UPDATE_ONE = 'updateOne';
	public const OP_UPDATE_MANY = 'updateMany';
	public const OP_DELETE_ONE = 'deleteOne';
	public const OP_DELETE_MANY = 'deleteMany';
	public const OP_AGGREGATE = 'aggregate';
	public const OP_COUNT = 'count';
	public const OP_DISTINCT = 'distinct';

	private TMongoConnection $_connection;
	private string $_collection;
	private string $_operation = self::OP_FIND;
	private array $_filter = [];
	private array $_document = [];
	private array $_documents = [];
	private array $_update = [];
	private array $_pipeline = [];
	private string $_distinctField = '';
	private array $_projection = [];
	private array $_sort = [];
	private int $_limit = 0;
	private int $_skip = 0;
	private array $_options = [];

	/**
	 * Constructor.
	 * @param TMongoConnection $connection the connection this command operates on.
	 * @param string $collection the collection name.
	 */
	public function __construct(TMongoConnection $connection, string $collection)
	{
		$this->_connection = $connection;
		$this->_collection = $collection;
		parent::__construct();
	}

	// -----------------------------------------------------------------------
	// IDataCommand — connection accessor
	// -----------------------------------------------------------------------

	/**
	 * @return TMongoConnection the connection associated with this command.
	 */
	public function getConnection(): TMongoConnection
	{
		return $this->_connection;
	}

	/**
	 * @return string the collection name this command targets.
	 */
	public function getCollection(): string
	{
		return $this->_collection;
	}

	// -----------------------------------------------------------------------
	// Builder setters (fluent)
	// -----------------------------------------------------------------------

	/**
	 * Sets the query filter document (analogous to a SQL WHERE clause).
	 * @param array $filter a MongoDB filter document.
	 * @return static
	 */
	public function setFilter(array $filter): static
	{
		$this->_filter = $filter;
		return $this;
	}

	/** @return array the configured filter. */
	public function getFilter(): array
	{
		return $this->_filter;
	}

	/**
	 * Sets the document for an insertOne operation.
	 * @param array $document the document to insert.
	 * @return static
	 */
	public function setDocument(array $document): static
	{
		$this->_document = $document;
		return $this;
	}

	/** @return array the configured document. */
	public function getDocument(): array
	{
		return $this->_document;
	}

	/**
	 * Sets the documents for an insertMany operation.
	 * @param array $documents an array of documents to insert.
	 * @return static
	 */
	public function setDocuments(array $documents): static
	{
		$this->_documents = $documents;
		return $this;
	}

	/** @return array the configured documents. */
	public function getDocuments(): array
	{
		return $this->_documents;
	}

	/**
	 * Sets the update specification (e.g. ['$set' => ['field' => 'value']]).
	 * @param array $update a MongoDB update document.
	 * @return static
	 */
	public function setUpdate(array $update): static
	{
		$this->_update = $update;
		return $this;
	}

	/** @return array the configured update document. */
	public function getUpdate(): array
	{
		return $this->_update;
	}

	/**
	 * Sets the aggregation pipeline.
	 * @param array $pipeline an array of pipeline stage documents.
	 * @return static
	 */
	public function setPipeline(array $pipeline): static
	{
		$this->_pipeline = $pipeline;
		return $this;
	}

	/** @return array the configured aggregation pipeline. */
	public function getPipeline(): array
	{
		return $this->_pipeline;
	}

	/**
	 * Sets the field name for a distinct operation.
	 * @param string $field the field whose distinct values to retrieve.
	 * @return static
	 */
	public function setDistinctField(string $field): static
	{
		$this->_distinctField = $field;
		return $this;
	}

	/** @return string the field name for a distinct operation. */
	public function getDistinctField(): string
	{
		return $this->_distinctField;
	}

	/**
	 * Sets a projection document to limit which fields are returned.
	 * @param array $projection a MongoDB projection document (e.g. ['name' => 1, '_id' => 0]).
	 * @return static
	 */
	public function setProjection(array $projection): static
	{
		$this->_projection = $projection;
		return $this;
	}

	/** @return array the configured projection. */
	public function getProjection(): array
	{
		return $this->_projection;
	}

	/**
	 * Sets the sort order.
	 * @param array $sort a MongoDB sort document (e.g. ['name' => 1, 'age' => -1]).
	 * @return static
	 */
	public function setSort(array $sort): static
	{
		$this->_sort = $sort;
		return $this;
	}

	/** @return array the configured sort document. */
	public function getSort(): array
	{
		return $this->_sort;
	}

	/**
	 * Sets the maximum number of documents to return.
	 * @param int $limit 0 means no limit.
	 * @return static
	 */
	public function setLimit(int $limit): static
	{
		$this->_limit = $limit;
		return $this;
	}

	/** @return int the configured limit. */
	public function getLimit(): int
	{
		return $this->_limit;
	}

	/**
	 * Sets the number of documents to skip before returning results.
	 * @param int $skip 0 means no skip.
	 * @return static
	 */
	public function setSkip(int $skip): static
	{
		$this->_skip = $skip;
		return $this;
	}

	/** @return int the configured skip count. */
	public function getSkip(): int
	{
		return $this->_skip;
	}

	/**
	 * Sets additional driver-level options merged into query or write calls.
	 * @param array $options driver options array.
	 * @return static
	 */
	public function setOptions(array $options): static
	{
		$this->_options = $options;
		return $this;
	}

	/** @return array the configured extra options. */
	public function getOptions(): array
	{
		return $this->_options;
	}

	/**
	 * Sets the operation type used by {@see execute} and {@see query}.
	 * Use one of the OP_* constants defined on this class.
	 * @param string $operation the operation identifier.
	 * @return static
	 */
	public function setOperation(string $operation): static
	{
		$this->_operation = $operation;
		return $this;
	}

	/** @return string the current operation type. */
	public function getOperation(): string
	{
		return $this->_operation;
	}

	// -----------------------------------------------------------------------
	// Internal helpers
	// -----------------------------------------------------------------------

	/**
	 * Returns the MongoDB namespace string ("database.collection").
	 * @return string the namespace for driver method calls.
	 */
	protected function getNamespace(): string
	{
		return $this->_connection->getCollectionNamespace($this->_collection);
	}

	/**
	 * Builds the Query options array from the current builder state.
	 * @return array options suitable for the {@see Query} constructor.
	 */
	protected function buildQueryOptions(): array
	{
		$options = $this->_options;
		if (!empty($this->_projection)) {
			$options['projection'] = $this->_projection;
		}
		if (!empty($this->_sort)) {
			$options['sort'] = $this->_sort;
		}
		if ($this->_limit > 0) {
			$options['limit'] = $this->_limit;
		}
		if ($this->_skip > 0) {
			$options['skip'] = $this->_skip;
		}
		return $options;
	}

	/**
	 * Returns a session option array if a transaction is currently active.
	 * Passed as the $options argument to Manager::executeQuery/executeBulkWrite/executeCommand.
	 * @return array empty array or ['session' => Session].
	 */
	protected function getSessionOption(): array
	{
		$tx = $this->_connection->getCurrentTransaction();
		if ($tx !== null) {
			return ['session' => $tx->getSession()];
		}
		return [];
	}

	/**
	 * Executes a BulkWrite and returns its WriteResult.
	 * @param BulkWrite $bulk the prepared bulk write operation.
	 * @return \MongoDB\Driver\WriteResult
	 */
	protected function executeBulk(BulkWrite $bulk): \MongoDB\Driver\WriteResult
	{
		return $this->_connection->getManager()->executeBulkWrite(
			$this->getNamespace(),
			$bulk,
			$this->getSessionOption()
		);
	}

	/**
	 * Executes a driver Query and returns a type-mapped cursor.
	 * @param array $filter the filter document.
	 * @param array $options query options.
	 * @return \MongoDB\Driver\Cursor
	 */
	protected function executeQuery(array $filter, array $options): \MongoDB\Driver\Cursor
	{
		$cursor = $this->_connection->getManager()->executeQuery(
			$this->getNamespace(),
			new Query($filter, $options),
			$this->getSessionOption()
		);
		$cursor->setTypeMap(['root' => 'array', 'document' => 'array', 'array' => 'array']);
		return $cursor;
	}

	/**
	 * Executes a driver Command against the configured database and returns a type-mapped cursor.
	 * @param array $cmd the command document.
	 * @return \MongoDB\Driver\Cursor
	 */
	protected function executeCommand(array $cmd): \MongoDB\Driver\Cursor
	{
		$db = $this->_connection->getDatabaseName() ?: 'admin';
		$cursor = $this->_connection->getManager()->executeCommand(
			$db,
			new Command($cmd),
			$this->getSessionOption()
		);
		$cursor->setTypeMap(['root' => 'array', 'document' => 'array', 'array' => 'array']);
		return $cursor;
	}

	// -----------------------------------------------------------------------
	// IDataCommand — execute() for write operations
	// -----------------------------------------------------------------------

	/**
	 * Executes the configured write operation.
	 *
	 * The operation type is determined by {@see setOperation}. Must be one of
	 * OP_INSERT_ONE, OP_INSERT_MANY, OP_UPDATE_ONE, OP_UPDATE_MANY,
	 * OP_DELETE_ONE, or OP_DELETE_MANY.
	 *
	 * @throws TDbException if the operation type is not a write operation or execution fails.
	 * @return int number of documents inserted, modified, or deleted.
	 */
	public function execute(): int
	{
		try {
			$bulk = new BulkWrite();

			switch ($this->_operation) {
				case self::OP_INSERT_ONE:
					$bulk->insert($this->_document);
					return (int) $this->executeBulk($bulk)->getInsertedCount();

				case self::OP_INSERT_MANY:
					foreach ($this->_documents as $doc) {
						$bulk->insert($doc);
					}
					return (int) $this->executeBulk($bulk)->getInsertedCount();

				case self::OP_UPDATE_ONE:
					$bulk->update($this->_filter, $this->_update, array_merge($this->_options, ['multi' => false]));
					return (int) $this->executeBulk($bulk)->getModifiedCount();

				case self::OP_UPDATE_MANY:
					$bulk->update($this->_filter, $this->_update, array_merge($this->_options, ['multi' => true]));
					return (int) $this->executeBulk($bulk)->getModifiedCount();

				case self::OP_DELETE_ONE:
					$bulk->delete($this->_filter, ['limit' => 1]);
					return (int) $this->executeBulk($bulk)->getDeletedCount();

				case self::OP_DELETE_MANY:
					$bulk->delete($this->_filter, ['limit' => 0]);
					return (int) $this->executeBulk($bulk)->getDeletedCount();

				default:
					throw new TDbException('mongocommand_execute_failed', "Operation '{$this->_operation}' is not a write operation; use query() for reads.");
			}
		} catch (TDbException $e) {
			throw $e;
		} catch (\Exception $e) {
			throw new TDbException('mongocommand_execute_failed', $e->getMessage());
		}
	}

	// -----------------------------------------------------------------------
	// IDataCommand — query() and convenience read methods
	// -----------------------------------------------------------------------

	/**
	 * Executes the configured read operation and returns a data reader.
	 *
	 * For OP_FIND (default) this performs a find() using the configured filter,
	 * projection, sort, limit, and skip. For OP_AGGREGATE it runs the configured
	 * aggregation pipeline.
	 *
	 * @throws TDbException on failure.
	 * @return TMongoDataReader a forward-only reader over the result documents.
	 */
	public function query(): TMongoDataReader
	{
		try {
			if ($this->_operation === self::OP_AGGREGATE) {
				return $this->aggregate($this->_pipeline);
			}
			$cursor = $this->executeQuery($this->_filter, $this->buildQueryOptions());
			return new TMongoDataReader($this, $cursor);
		} catch (TDbException $e) {
			throw $e;
		} catch (\Exception $e) {
			throw new TDbException('mongocommand_query_failed', $e->getMessage());
		}
	}

	/**
	 * Executes a find query and returns the first matching document.
	 *
	 * Uses the configured filter and options unless $fetchAssociative is false,
	 * in which case a numeric-indexed array is returned.
	 *
	 * @param bool $fetchAssociative ignored for MongoDB (always associative); kept for interface parity.
	 * @throws TDbException on failure.
	 * @return array|false the first matching document, or false if none.
	 */
	public function queryRow($fetchAssociative = true): array|false
	{
		return $this->findOne($this->_filter) ?? false;
	}

	/**
	 * Executes a find query and returns the value of the first field in the first document.
	 * @throws TDbException on failure.
	 * @return mixed the scalar value, or false if no document was found.
	 */
	public function queryScalar(): mixed
	{
		$row = $this->queryRow();
		if ($row === false) {
			return false;
		}
		return reset($row);
	}

	/**
	 * Executes a find query and returns the values of the first field across all documents.
	 * @throws TDbException on failure.
	 * @return array the first-field values of every matching document.
	 */
	public function queryColumn(): array
	{
		$rows = $this->query()->readAll();
		$column = [];
		foreach ($rows as $row) {
			$column[] = reset($row);
		}
		return $column;
	}

	/**
	 * Executes a find query and returns all matching documents as an array.
	 * @throws TDbException on failure.
	 * @return array all matching documents.
	 */
	public function queryAll(): array
	{
		return $this->query()->readAll();
	}

	// -----------------------------------------------------------------------
	// MongoDB-specific direct operation methods
	// -----------------------------------------------------------------------

	/**
	 * Finds a single document matching the given filter.
	 * @param array $filter the query filter (defaults to the builder filter if empty).
	 * @param array $options additional query options.
	 * @throws TDbException on failure.
	 * @return null|array the matching document, or null if none.
	 */
	public function findOne(array $filter = [], array $options = []): ?array
	{
		try {
			$f = $filter !== [] ? $filter : $this->_filter;
			$opts = array_merge($this->buildQueryOptions(), $options, ['limit' => 1]);
			$cursor = $this->executeQuery($f, $opts);
			$results = $cursor->toArray();
			return $results !== [] ? $results[0] : null;
		} catch (\Exception $e) {
			throw new TDbException('mongocommand_query_failed', $e->getMessage());
		}
	}

	/**
	 * Finds all documents matching the given filter.
	 * @param array $filter the query filter (defaults to the builder filter if empty).
	 * @param array $options additional query options (sort, limit, skip, projection, ...).
	 * @throws TDbException on failure.
	 * @return TMongoDataReader a forward-only reader over the matching documents.
	 */
	public function findMany(array $filter = [], array $options = []): TMongoDataReader
	{
		try {
			$f = $filter !== [] ? $filter : $this->_filter;
			$opts = array_merge($this->buildQueryOptions(), $options);
			$cursor = $this->executeQuery($f, $opts);
			return new TMongoDataReader($this, $cursor);
		} catch (\Exception $e) {
			throw new TDbException('mongocommand_query_failed', $e->getMessage());
		}
	}

	/**
	 * Inserts a single document into the collection.
	 * @param array $document the document to insert (defaults to the builder document if empty).
	 * @throws TDbException on failure.
	 * @return mixed the generated _id of the inserted document.
	 */
	public function insertOne(array $document = []): mixed
	{
		try {
			$doc = $document !== [] ? $document : $this->_document;
			$bulk = new BulkWrite();
			$id = $bulk->insert($doc);
			$this->executeBulk($bulk);
			return $id;
		} catch (\Exception $e) {
			throw new TDbException('mongocommand_execute_failed', $e->getMessage());
		}
	}

	/**
	 * Inserts multiple documents into the collection.
	 * @param array $documents an array of documents to insert (defaults to the builder documents if empty).
	 * @throws TDbException on failure.
	 * @return array the generated _id values of the inserted documents.
	 */
	public function insertMany(array $documents = []): array
	{
		try {
			$docs = $documents !== [] ? $documents : $this->_documents;
			$bulk = new BulkWrite();
			$ids = [];
			foreach ($docs as $doc) {
				$ids[] = $bulk->insert($doc);
			}
			$this->executeBulk($bulk);
			return $ids;
		} catch (\Exception $e) {
			throw new TDbException('mongocommand_execute_failed', $e->getMessage());
		}
	}

	/**
	 * Updates the first document matching the given filter.
	 * @param array $filter the query filter.
	 * @param array $update the update specification (e.g. ['$set' => ['field' => 'value']]).
	 * @param array $options additional options (e.g. ['upsert' => true]).
	 * @throws TDbException on failure.
	 * @return int the number of documents modified.
	 */
	public function updateOne(array $filter, array $update, array $options = []): int
	{
		try {
			$bulk = new BulkWrite();
			$bulk->update($filter, $update, array_merge($options, ['multi' => false]));
			return (int) $this->executeBulk($bulk)->getModifiedCount();
		} catch (\Exception $e) {
			throw new TDbException('mongocommand_execute_failed', $e->getMessage());
		}
	}

	/**
	 * Updates all documents matching the given filter.
	 * @param array $filter the query filter.
	 * @param array $update the update specification.
	 * @param array $options additional options (e.g. ['upsert' => true]).
	 * @throws TDbException on failure.
	 * @return int the number of documents modified.
	 */
	public function updateMany(array $filter, array $update, array $options = []): int
	{
		try {
			$bulk = new BulkWrite();
			$bulk->update($filter, $update, array_merge($options, ['multi' => true]));
			return (int) $this->executeBulk($bulk)->getModifiedCount();
		} catch (\Exception $e) {
			throw new TDbException('mongocommand_execute_failed', $e->getMessage());
		}
	}

	/**
	 * Deletes the first document matching the given filter.
	 * @param array $filter the query filter.
	 * @param array $options additional options.
	 * @throws TDbException on failure.
	 * @return int the number of documents deleted.
	 */
	public function deleteOne(array $filter, array $options = []): int
	{
		try {
			$bulk = new BulkWrite();
			$bulk->delete($filter, array_merge($options, ['limit' => 1]));
			return (int) $this->executeBulk($bulk)->getDeletedCount();
		} catch (\Exception $e) {
			throw new TDbException('mongocommand_execute_failed', $e->getMessage());
		}
	}

	/**
	 * Deletes all documents matching the given filter.
	 * @param array $filter the query filter.
	 * @param array $options additional options.
	 * @throws TDbException on failure.
	 * @return int the number of documents deleted.
	 */
	public function deleteMany(array $filter, array $options = []): int
	{
		try {
			$bulk = new BulkWrite();
			$bulk->delete($filter, array_merge($options, ['limit' => 0]));
			return (int) $this->executeBulk($bulk)->getDeletedCount();
		} catch (\Exception $e) {
			throw new TDbException('mongocommand_execute_failed', $e->getMessage());
		}
	}

	/**
	 * Runs an aggregation pipeline against the collection.
	 * @param array $pipeline an array of aggregation stage documents (defaults to builder pipeline if empty).
	 * @param array $options additional command options.
	 * @throws TDbException on failure.
	 * @return TMongoDataReader a reader over the aggregation result documents.
	 */
	public function aggregate(array $pipeline = [], array $options = []): TMongoDataReader
	{
		try {
			$p = $pipeline !== [] ? $pipeline : $this->_pipeline;
			$sessionOpt = $this->getSessionOption();
			$cmd = array_merge(
				['aggregate' => $this->_collection, 'pipeline' => $p, 'cursor' => new \stdClass()],
				$options,
				$sessionOpt
			);
			$cursor = $this->executeCommand($cmd);
			return new TMongoDataReader($this, $cursor);
		} catch (TDbException $e) {
			throw $e;
		} catch (\Exception $e) {
			throw new TDbException('mongocommand_query_failed', $e->getMessage());
		}
	}

	/**
	 * Counts the documents matching the given filter.
	 * @param array $filter the query filter (defaults to the builder filter if empty).
	 * @param array $options additional command options.
	 * @throws TDbException on failure.
	 * @return int the number of matching documents.
	 */
	public function count(array $filter = [], array $options = []): int
	{
		try {
			$f = $filter !== [] ? $filter : $this->_filter;
			$cmd = array_merge(['count' => $this->_collection, 'query' => $f], $options);
			$cursor = $this->executeCommand($cmd);
			$result = current($cursor->toArray());
			return (int) ($result['n'] ?? 0);
		} catch (\Exception $e) {
			throw new TDbException('mongocommand_query_failed', $e->getMessage());
		}
	}

	/**
	 * Returns the distinct values for a given field across the matching documents.
	 * @param string $field the field name (defaults to the builder distinct field if empty).
	 * @param array $filter the query filter (defaults to the builder filter if empty).
	 * @param array $options additional command options.
	 * @throws TDbException on failure.
	 * @return array the distinct field values.
	 */
	public function distinct(string $field = '', array $filter = [], array $options = []): array
	{
		try {
			$f = $field !== '' ? $field : $this->_distinctField;
			$filt = $filter !== [] ? $filter : $this->_filter;
			$cmd = array_merge(['distinct' => $this->_collection, 'key' => $f, 'query' => $filt], $options);
			$cursor = $this->executeCommand($cmd);
			$result = current($cursor->toArray());
			return (array) ($result['values'] ?? []);
		} catch (\Exception $e) {
			throw new TDbException('mongocommand_query_failed', $e->getMessage());
		}
	}
}

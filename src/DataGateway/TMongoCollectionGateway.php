<?php

/**
 * TMongoCollectionGateway class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Data\DataGateway;

use MongoDB\BSON\ObjectId;
use Prado\Data\Common\Mongo\TMongoCollectionInfo;
use Prado\Data\Common\Mongo\TMongoCommandBuilder;
use Prado\Data\TMongoCommand;
use Prado\Data\TMongoConnection;
use Prado\Data\TMongoDataReader;
use Prado\Exceptions\TDbException;

/**
 * TMongoCollectionGateway provides CRUD operations for a single MongoDB collection.
 *
 * TMongoCollectionGateway is the MongoDB analogue of {@see TTableGateway} and
 * follows the same Table Gateway pattern: each instance is bound to one collection
 * and a connection, exposing find, insert, update, delete, and aggregate methods.
 *
 * It is stateless with respect to data — its role is to push data in and out of
 * the collection, leaving document lifecycle management to the caller.
 *
 * ```php
 * $conn = new TMongoConnection('mongodb://localhost:27017', '', '', 'mydb');
 * $conn->Active = true;
 *
 * $users = new TMongoCollectionGateway('users', $conn);
 *
 * // Insert
 * $id = $users->insert(['name' => 'Alice', 'age' => 30]);
 *
 * // Find
 * $alice = $users->findById($id);
 * $adults = $users->findAll(['age' => ['$gte' => 18]]);
 *
 * // Update
 * $users->updateById($id, ['$set' => ['age' => 31]]);
 *
 * // Delete
 * $users->deleteById($id);
 * ```
 *
 * **Events**
 *
 * Two events mirror those on {@see TTableGateway}:
 *
 * - `OnCreateCommand`  — raised after a {@see TMongoCommand} is prepared.
 *   The parameter is a {@see TDataGatewayEventParameter} whose `Command` property
 *   is the command about to be executed.
 *
 * - `OnExecuteCommand` — raised after a command is executed.
 *   The parameter is a {@see TDataGatewayResultEventParameter} whose `Result`
 *   property contains the return value and may be replaced by the handler.
 *
 * **Dynamic finders**
 *
 * Like {@see TTableGateway}, magic `__call` translates `findByFieldName($value)`,
 * `findAllByFieldName($value)`, and `deleteByFieldName($value)` into the
 * corresponding filter-based operations.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 1.0.0
 */
class TMongoCollectionGateway extends \Prado\TComponent
{
	private TMongoConnection $_connection;
	private string $_collectionName;
	private ?TMongoCollectionInfo $_collectionInfo = null;

	/**
	 * Creates a gateway for the given collection.
	 * @param string $collection the collection name.
	 * @param TMongoConnection $connection the active MongoDB connection.
	 */
	public function __construct(string $collection, TMongoConnection $connection)
	{
		$this->_collectionName = $collection;
		$this->_connection = $connection;
		parent::__construct();
	}

	// -----------------------------------------------------------------------
	// Accessors
	// -----------------------------------------------------------------------

	/**
	 * @return TMongoConnection the connection this gateway operates on.
	 */
	public function getDbConnection(): TMongoConnection
	{
		return $this->_connection;
	}

	/**
	 * @return string the collection name.
	 */
	public function getCollectionName(): string
	{
		return $this->_collectionName;
	}

	/**
	 * Returns the collection info (schema + indexes), loading it lazily.
	 * @return TMongoCollectionInfo the collection info.
	 */
	public function getCollectionInfo(): TMongoCollectionInfo
	{
		if ($this->_collectionInfo === null) {
			$this->_collectionInfo = $this->_connection->getDbMetaData()->getCollectionInfo($this->_collectionName);
		}
		return $this->_collectionInfo;
	}

	/**
	 * Returns a command builder for this collection.
	 * @return TMongoCommandBuilder
	 */
	protected function getCommandBuilder(): TMongoCommandBuilder
	{
		return $this->getCollectionInfo()->createCommandBuilder($this->_connection);
	}

	/**
	 * Creates a fresh {@see TMongoCommand} for this collection.
	 * @return TMongoCommand
	 */
	protected function createCommand(): TMongoCommand
	{
		return $this->_connection->createCommand($this->_collectionName);
	}

	// -----------------------------------------------------------------------
	// Internal event helpers
	// -----------------------------------------------------------------------

	/**
	 * Raises OnCreateCommand and returns the (possibly replaced) command.
	 * @param TMongoCommand $command the command about to be executed.
	 * @return TMongoCommand
	 */
	protected function onCreateCommand(TMongoCommand $command): TMongoCommand
	{
		// Second argument is null because MongoDB uses filter arrays, not TSqlCriteria.
		$param = new TDataGatewayEventParameter($command, null);
		$this->raiseEvent('OnCreateCommand', $this, $param);
		return $param->getCommand();
	}

	/**
	 * Raises OnExecuteCommand and returns the (possibly replaced) result.
	 * @param TMongoCommand $command the command that was executed.
	 * @param mixed $result the raw result.
	 * @return mixed the (possibly event-modified) result.
	 */
	protected function onExecuteCommand(TMongoCommand $command, mixed $result): mixed
	{
		$param = new TDataGatewayResultEventParameter($command, $result);
		$this->raiseEvent('OnExecuteCommand', $this, $param);
		return $param->getResult();
	}

	// -----------------------------------------------------------------------
	// Read operations
	// -----------------------------------------------------------------------

	/**
	 * Finds the first document matching the given filter.
	 * @param array $filter the MongoDB query filter.
	 * @param array $options additional query options (projection, sort, …).
	 * @return null|array the matching document, or null if none found.
	 */
	public function find(array $filter = [], array $options = []): ?array
	{
		$cmd = $this->onCreateCommand($this->createCommand());
		$result = $cmd->findOne($filter, $options);
		return $this->onExecuteCommand($cmd, $result);
	}

	/**
	 * Finds all documents matching the given filter.
	 * @param null|array $filter the MongoDB query filter; null means no filter (all documents).
	 * @param array $options additional query options (sort, limit, skip, projection, …).
	 * @return TMongoDataReader a forward-only reader over the matching documents.
	 */
	public function findAll(?array $filter = null, array $options = []): TMongoDataReader
	{
		$cmd = $this->onCreateCommand($this->createCommand());
		$result = $cmd->findMany($filter ?? [], $options);
		return $this->onExecuteCommand($cmd, $result);
	}

	/**
	 * Finds a single document by its `_id` field.
	 * @param mixed $id an {@see ObjectId} or a string/integer that can be cast to one.
	 * @return null|array the matching document, or null if not found.
	 */
	public function findById(mixed $id): ?array
	{
		return $this->find(['_id' => $this->normalizeId($id)]);
	}

	/**
	 * Finds all documents whose `_id` is in the given array.
	 * @param array $ids an array of ids (ObjectId instances or castable values).
	 * @return TMongoDataReader
	 */
	public function findAllByIds(array $ids): TMongoDataReader
	{
		$objectIds = array_map([$this, 'normalizeId'], $ids);
		return $this->findAll(['_id' => ['$in' => $objectIds]]);
	}

	/**
	 * Counts documents matching the given filter.
	 * @param null|array $filter the query filter; null means all documents.
	 * @param array $options additional command options.
	 * @return int the document count.
	 */
	public function count(?array $filter = null, array $options = []): int
	{
		$cmd = $this->onCreateCommand($this->createCommand());
		$result = $cmd->count($filter ?? [], $options);
		return (int) $this->onExecuteCommand($cmd, $result);
	}

	// -----------------------------------------------------------------------
	// Write operations
	// -----------------------------------------------------------------------

	/**
	 * Inserts a single document and returns its generated `_id`.
	 * @param array $document the document to insert.
	 * @return mixed the generated `_id` (usually a {@see ObjectId}).
	 */
	public function insert(array $document): mixed
	{
		$cmd = $this->onCreateCommand($this->createCommand());
		$result = $cmd->insertOne($document);
		return $this->onExecuteCommand($cmd, $result);
	}

	/**
	 * Inserts multiple documents and returns their generated `_id` values.
	 * @param array $documents an array of documents to insert.
	 * @return array the generated `_id` values.
	 */
	public function insertMany(array $documents): array
	{
		$cmd = $this->onCreateCommand($this->createCommand());
		$result = $cmd->insertMany($documents);
		return (array) $this->onExecuteCommand($cmd, $result);
	}

	/**
	 * Updates documents matching the given filter.
	 * @param array $filter the query filter.
	 * @param array $update the update specification (e.g. ['$set' => ['field' => 'value']]).
	 * @param array $options additional options. Pass ['multi' => false] to limit to one document.
	 * @return int the number of documents modified.
	 */
	public function update(array $filter, array $update, array $options = []): int
	{
		$cmd = $this->onCreateCommand($this->createCommand());
		$multi = $options['multi'] ?? true;
		unset($options['multi']);
		$result = $multi
			? $cmd->updateMany($filter, $update, $options)
			: $cmd->updateOne($filter, $update, $options);
		return (int) $this->onExecuteCommand($cmd, $result);
	}

	/**
	 * Updates the document with the given `_id`.
	 * @param mixed $id the document id.
	 * @param array $update the update specification.
	 * @return int the number of documents modified (0 or 1).
	 */
	public function updateById(mixed $id, array $update): int
	{
		return $this->update(['_id' => $this->normalizeId($id)], $update, ['multi' => false]);
	}

	/**
	 * Deletes documents matching the given filter.
	 * @param array $filter the query filter.
	 * @param array $options additional options. Pass ['multi' => false] to limit to one document.
	 * @return int the number of documents deleted.
	 */
	public function delete(array $filter, array $options = []): int
	{
		$cmd = $this->onCreateCommand($this->createCommand());
		$multi = $options['multi'] ?? true;
		unset($options['multi']);
		$result = $multi
			? $cmd->deleteMany($filter, $options)
			: $cmd->deleteOne($filter, $options);
		return (int) $this->onExecuteCommand($cmd, $result);
	}

	/**
	 * Deletes the document with the given `_id`.
	 * @param mixed $id the document id.
	 * @return int the number of documents deleted (0 or 1).
	 */
	public function deleteById(mixed $id): int
	{
		return $this->delete(['_id' => $this->normalizeId($id)], ['multi' => false]);
	}

	// -----------------------------------------------------------------------
	// Aggregation
	// -----------------------------------------------------------------------

	/**
	 * Runs an aggregation pipeline against the collection.
	 * @param array $pipeline an array of aggregation stage documents.
	 * @param array $options additional command options.
	 * @return TMongoDataReader a reader over the aggregation result documents.
	 */
	public function aggregate(array $pipeline, array $options = []): TMongoDataReader
	{
		$cmd = $this->onCreateCommand($this->createCommand());
		$result = $cmd->aggregate($pipeline, $options);
		return $this->onExecuteCommand($cmd, $result);
	}

	// -----------------------------------------------------------------------
	// Dynamic finder methods
	// -----------------------------------------------------------------------

	/**
	 * Translates dynamic `findByFieldName`, `findAllByFieldName`, and
	 * `deleteByFieldName` calls into the corresponding filter-based operations.
	 *
	 * Examples:
	 * - `$gateway->findByEmail('a@b.com')`     → `find(['email' => 'a@b.com'])`
	 * - `$gateway->findAllByStatus('active')`  → `findAll(['status' => 'active'])`
	 * - `$gateway->deleteByStatus('inactive')` → `delete(['status' => 'inactive'])`
	 *
	 * @param mixed $name
	 * @param mixed $args
	 * @throws TDbException if the method name does not match a known pattern.
	 */
	public function __call($name, $args): mixed
	{
		if (str_starts_with($name, 'findAllBy')) {
			$field = lcfirst(substr($name, 9));
			return $this->findAll([$field => $args[0]]);
		}
		if (str_starts_with($name, 'findBy')) {
			$field = lcfirst(substr($name, 6));
			return $this->find([$field => $args[0]]);
		}
		if (str_starts_with($name, 'deleteBy')) {
			$field = lcfirst(substr($name, 8));
			return $this->delete([$field => $args[0]]);
		}
		return parent::__call($name, $args);
	}

	// -----------------------------------------------------------------------
	// Utility
	// -----------------------------------------------------------------------

	/**
	 * Normalises a raw id value into a {@see ObjectId}.
	 *
	 * If the value is already an ObjectId it is returned as-is. String values
	 * that look like valid 24-hex-character ObjectId strings are converted.
	 * All other values are passed through unchanged (e.g. integer _id fields).
	 *
	 * @param mixed $id the raw id value.
	 * @return mixed an ObjectId or the original value.
	 */
	protected function normalizeId(mixed $id): mixed
	{
		if ($id instanceof ObjectId) {
			return $id;
		}
		if (is_string($id) && preg_match('/^[0-9a-f]{24}$/i', $id)) {
			return new ObjectId($id);
		}
		return $id;
	}
}

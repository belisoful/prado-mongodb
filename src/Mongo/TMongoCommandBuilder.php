<?php

/**
 * TMongoCommandBuilder class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Data\Common\Mongo;

use Prado\Data\TMongoCommand;
use Prado\Data\TMongoConnection;

/**
 * TMongoCommandBuilder generates preconfigured {@see TMongoCommand} objects for
 * common CRUD operations on a specific collection.
 *
 * It is the MongoDB analogue of {@see \Prado\Data\Common\TDbCommandBuilder} and
 * is used internally by {@see TMongoCollectionInfo} and {@see \Prado\Data\DataGateway\TMongoCollectionGateway}
 * to build commands without repeating boilerplate.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 4.3.3
 */
class TMongoCommandBuilder extends \Prado\TComponent
{
	private TMongoConnection $_connection;
	private TMongoCollectionInfo $_collectionInfo;

	/**
	 * Constructor.
	 * @param TMongoConnection $connection the connection to execute commands on.
	 * @param TMongoCollectionInfo $collectionInfo metadata for the collection.
	 */
	public function __construct(TMongoConnection $connection, TMongoCollectionInfo $collectionInfo)
	{
		$this->_connection = $connection;
		$this->_collectionInfo = $collectionInfo;
		parent::__construct();
	}

	/**
	 * @return TMongoConnection the connection this builder operates on.
	 */
	public function getDbConnection(): TMongoConnection
	{
		return $this->_connection;
	}

	/**
	 * @return TMongoCollectionInfo the collection metadata.
	 */
	public function getCollectionInfo(): TMongoCollectionInfo
	{
		return $this->_collectionInfo;
	}

	// -----------------------------------------------------------------------
	// Query-option helpers
	// -----------------------------------------------------------------------

	/**
	 * Applies limit and skip to a query options array.
	 * @param array $options query options to modify in place.
	 * @param int $limit maximum documents to return; 0 or negative means no limit.
	 * @param int $skip documents to skip; 0 or negative means no skip.
	 */
	public function applyLimitSkip(array &$options, int $limit, int $skip): void
	{
		if ($limit > 0) {
			$options['limit'] = $limit;
		}
		if ($skip > 0) {
			$options['skip'] = $skip;
		}
	}

	/**
	 * Applies a sort document to query options.
	 * @param array $options query options to modify in place.
	 * @param array $sort sort document, e.g. ['name' => 1, 'createdAt' => -1].
	 */
	public function applySort(array &$options, array $sort): void
	{
		if ($sort !== []) {
			$options['sort'] = $sort;
		}
	}

	/**
	 * Applies a field projection to query options.
	 *
	 * @param array $options query options to modify in place.
	 * @param mixed $fields
	 *   - null  → all known field names as an inclusion projection.
	 *   - '*'   → no projection (all fields, MongoDB default).
	 *   - array → used verbatim as the projection document.
	 *   - string (comma-separated field names) → converted to an inclusion projection.
	 */
	public function applyProjection(array &$options, mixed $fields): void
	{
		if ($fields === '*' || $fields === []) {
			return;
		}
		if ($fields === null) {
			// Project all known schema fields
			$knownFields = $this->_collectionInfo->getFieldNames();
			if ($knownFields !== []) {
				$options['projection'] = array_fill_keys($knownFields, 1);
			}
			return;
		}
		if (is_array($fields)) {
			$options['projection'] = $fields;
			return;
		}
		if (is_string($fields)) {
			$list = array_filter(array_map('trim', explode(',', $fields)));
			if ($list !== []) {
				$options['projection'] = array_fill_keys($list, 1);
			}
		}
	}

	/**
	 * Builds a MongoDB filter that performs a case-insensitive keyword search
	 * across the given fields using regex matching.
	 *
	 * Equivalent to TDbCommandBuilder::getSearchExpression for MongoDB.
	 *
	 * @param array $fields field names to search in.
	 * @param string $keywords space-separated search terms.
	 * @return array a MongoDB filter document, or [] if no terms/fields provided.
	 */
	public function getSearchFilter(array $fields, string $keywords): array
	{
		$words = array_filter(preg_split('/\s+/', trim($keywords)));
		if ($words === [] || $fields === []) {
			return [];
		}
		$conditions = [];
		foreach ($fields as $field) {
			foreach ($words as $word) {
				$conditions[] = [$field => ['$regex' => preg_quote((string) $word, '/'), '$options' => 'i']];
			}
		}
		return ['$or' => $conditions];
	}

	/**
	 * Returns the field names to include in a select operation.
	 *
	 * @param mixed $data
	 *   - '*'   → [] (no projection; MongoDB returns all fields).
	 *   - null  → all known schema field names.
	 *   - string → comma-split field names.
	 *   - array → array_keys($data).
	 * @return array field names.
	 */
	public function getSelectFieldList(mixed $data = '*'): array
	{
		if ($data === '*') {
			return [];
		}
		if ($data === null) {
			return $this->_collectionInfo->getFieldNames();
		}
		if (is_string($data)) {
			return array_filter(array_map('trim', explode(',', $data)));
		}
		if (is_array($data)) {
			return array_keys($data);
		}
		return [];
	}

	// -----------------------------------------------------------------------
	// Command factory methods
	// -----------------------------------------------------------------------

	/**
	 * Creates a command configured for a find operation.
	 * @param array $filter query filter.
	 * @param array $options query options (sort, limit, skip, projection, …).
	 * @return TMongoCommand
	 */
	public function createFindCommand(array $filter = [], array $options = []): TMongoCommand
	{
		$cmd = $this->_connection->createCommand($this->_collectionInfo->getCollectionName());
		$cmd->setFilter($filter)->setOptions($options)->setOperation(TMongoCommand::OP_FIND);
		return $cmd;
	}

	/**
	 * Creates a command configured for an insertOne operation.
	 * @param array $document the document to insert.
	 * @return TMongoCommand
	 */
	public function createInsertOneCommand(array $document): TMongoCommand
	{
		$cmd = $this->_connection->createCommand($this->_collectionInfo->getCollectionName());
		$cmd->setDocument($document)->setOperation(TMongoCommand::OP_INSERT_ONE);
		return $cmd;
	}

	/**
	 * Creates a command configured for an insertMany operation.
	 * @param array $documents the documents to insert.
	 * @return TMongoCommand
	 */
	public function createInsertManyCommand(array $documents): TMongoCommand
	{
		$cmd = $this->_connection->createCommand($this->_collectionInfo->getCollectionName());
		$cmd->setDocuments($documents)->setOperation(TMongoCommand::OP_INSERT_MANY);
		return $cmd;
	}

	/**
	 * Creates a command configured for an updateOne operation.
	 * @param array $filter query filter.
	 * @param array $update update specification.
	 * @param array $options additional options (e.g. ['upsert' => true]).
	 * @return TMongoCommand
	 */
	public function createUpdateOneCommand(array $filter, array $update, array $options = []): TMongoCommand
	{
		$cmd = $this->_connection->createCommand($this->_collectionInfo->getCollectionName());
		$cmd->setFilter($filter)->setUpdate($update)->setOptions($options)->setOperation(TMongoCommand::OP_UPDATE_ONE);
		return $cmd;
	}

	/**
	 * Creates a command configured for an updateMany operation.
	 * @param array $filter query filter.
	 * @param array $update update specification.
	 * @param array $options additional options.
	 * @return TMongoCommand
	 */
	public function createUpdateManyCommand(array $filter, array $update, array $options = []): TMongoCommand
	{
		$cmd = $this->_connection->createCommand($this->_collectionInfo->getCollectionName());
		$cmd->setFilter($filter)->setUpdate($update)->setOptions($options)->setOperation(TMongoCommand::OP_UPDATE_MANY);
		return $cmd;
	}

	/**
	 * Creates a command configured for a deleteOne operation.
	 * @param array $filter query filter.
	 * @return TMongoCommand
	 */
	public function createDeleteOneCommand(array $filter): TMongoCommand
	{
		$cmd = $this->_connection->createCommand($this->_collectionInfo->getCollectionName());
		$cmd->setFilter($filter)->setOperation(TMongoCommand::OP_DELETE_ONE);
		return $cmd;
	}

	/**
	 * Creates a command configured for a deleteMany operation.
	 * @param array $filter query filter.
	 * @return TMongoCommand
	 */
	public function createDeleteManyCommand(array $filter): TMongoCommand
	{
		$cmd = $this->_connection->createCommand($this->_collectionInfo->getCollectionName());
		$cmd->setFilter($filter)->setOperation(TMongoCommand::OP_DELETE_MANY);
		return $cmd;
	}

	/**
	 * Creates a command configured for a count operation.
	 * @param array $filter query filter.
	 * @return TMongoCommand
	 */
	public function createCountCommand(array $filter = []): TMongoCommand
	{
		$cmd = $this->_connection->createCommand($this->_collectionInfo->getCollectionName());
		$cmd->setFilter($filter)->setOperation(TMongoCommand::OP_COUNT);
		return $cmd;
	}
}

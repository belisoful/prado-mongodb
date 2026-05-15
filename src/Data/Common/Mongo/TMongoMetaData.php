<?php

/**
 * TMongoMetaData class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Data\Common\Mongo;

use MongoDB\Driver\Command;
use Prado\Data\TMongoConnection;
use Prado\Exceptions\TDbException;

/**
 * TMongoMetaData provides schema and index metadata for a MongoDB database.
 *
 * It is the MongoDB analogue of {@see \Prado\Data\Common\TDbMetaData} and is
 * returned by {@see TMongoConnection::getDbMetaData}.
 *
 * Because MongoDB is schemaless by default, collection metadata is derived from:
 *
 * 1. The collection's JSON Schema validator (`$jsonSchema` in the `validator` option),
 *    if one has been configured via `db.createCollection()` or `collMod`.
 * 2. The collection's index list (from `listIndexes`), always available.
 *
 * Collection info objects are cached per collection name.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 1.0.0
 */
class TMongoMetaData extends \Prado\TComponent implements \Prado\Data\Common\IDataMetaData
{
	private TMongoConnection $_connection;

	/** @var TMongoCollectionInfo[] cache keyed by collection name. */
	private array $_collectionInfoCache = [];

	/**
	 * Constructor.
	 * @param TMongoConnection $conn the active MongoDB connection.
	 */
	public function __construct(TMongoConnection $conn)
	{
		$this->_connection = $conn;
		parent::__construct();
	}

	/**
	 * @return TMongoConnection the connection this metadata object introspects.
	 */
	public function getDbConnection()
	{
		return $this->_connection;
	}

	/**
	 * Retrieves metadata for a specific collection.
	 * @param null|string $collectionName the collection name. If null, returns metadata for the current database.
	 * @return TMongoCollectionInfo the collection metadata.
	 */
	public function getTableInfo($collectionName = null)
	{
		if ($collectionName === null) {
			// For MongoDB, when no collection is specified, we return info about the first collection
			$collections = $this->findCollectionNames();
			if (empty($collections)) {
				throw new \Prado\Exceptions\TDbException('mongometadata_no_collections');
			}
			$collectionName = $collections[0];
		}
		return $this->getCollectionInfo($collectionName);
	}

	/**
	 * Creates a command builder for performing CRUD operations on a specific collection.
	 * @param null|string $collectionName the collection name.
	 * @return TMongoCommandBuilder the command builder instance for the given collection.
	 */
	public function createCommandBuilder($collectionName = null)
	{
		return $this->getCollectionInfo($collectionName)->createCommandBuilder($this->_connection);
	}

	/**
	 * Quotes a collection name for use in MongoDB queries.
	 * MongoDB doesn't require quoting, so we return the name as-is.
	 * @param string $name the collection name to quote.
	 * @return string the collection name.
	 */
	public function quoteTableName($name)
	{
		return $name;
	}

	/**
	 * Quotes a field name for use in MongoDB queries.
	 * MongoDB doesn't require quoting, so we return the name as-is.
	 * @param string $name the field name to quote.
	 * @return string the field name.
	 */
	public function quoteColumnName($name)
	{
		return $name;
	}

	/**
	 * Quotes a field alias for use in MongoDB queries.
	 * MongoDB doesn't require quoting, so we return the name as-is.
	 * @param string $name the field alias to quote.
	 * @return string the field alias.
	 */
	public function quoteColumnAlias($name)
	{
		return $name;
	}

	/**
	 * Returns all collection names in the configured database.
	 * @param string $schema the schema name. Not used in MongoDB.
	 * @return string[] the collection names.
	 */
	public function findTableNames($schema = '')
	{
		return $this->findCollectionNames();
	}

	/**
	 * Returns the collection info for a given collection name, with caching.
	 * @param string $collectionName the collection to introspect.
	 * @throws TDbException if the database command fails.
	 * @return TMongoCollectionInfo the collection info.
	 */
	public function getCollectionInfo(string $collectionName): TMongoCollectionInfo
	{
		if (!isset($this->_collectionInfoCache[$collectionName])) {
			$this->_collectionInfoCache[$collectionName] = $this->createCollectionInfo($collectionName);
		}
		return $this->_collectionInfoCache[$collectionName];
	}

	/**
	 * Creates a {@see TMongoCollectionInfo} by introspecting the collection.
	 *
	 * Queries `listCollections` to obtain the validator (if any) and
	 * `listIndexes` to obtain indexes.
	 *
	 * @param string $collectionName the collection to introspect.
	 * @throws TDbException if a driver command fails.
	 * @return TMongoCollectionInfo the populated collection info.
	 */
	protected function createCollectionInfo(string $collectionName): TMongoCollectionInfo
	{
		$db = $this->_connection->getDatabaseName();
		$manager = $this->_connection->getManager();

		// 1. Fetch validator / options via listCollections
		$cursor = $manager->executeCommand($db, new Command([
			'listCollections' => 1,
			//'filter' => ['name' => $collectionName],
			'nameOnly' => false,
		]));
		$cursor->setTypeMap(['root' => 'array', 'document' => 'array', 'array' => 'array']);
		//$collections = $cursor->toArray();
		$collections = [];
		foreach ($cursor as $info) {
			if ($info['name'] === $collectionName) {
				$collections[] = $info;
				break;
			}
		}

		$options = $collections !== [] ? ($collections[0]['options'] ?? []) : [];
		$validator = $options['validator'] ?? [];
		$jsonSchema = $validator['$jsonSchema'] ?? [];

		// 2. Parse fields from the JSON Schema properties
		$fields = $this->parseSchemaFields($jsonSchema);

		// If no schema, inspect a sample document from the collection
		if ($fields === []) {
			// new to fix unit test
			try {
				$query = new \MongoDB\Driver\Query([], ['limit' => 1]);
				$samples = $manager->executeQuery($db . '.' . $collectionName, $query);
				$samples->setTypeMap(['root' => 'array', 'document' => 'array', 'array' => 'array']);
				foreach ($samples as $sample) {
					$fields = $this->inferFieldsFromDocument($sample);
					break;
				}
			} catch (\Exception $e) {
				// Ignore - leave fields empty
			}
		}
		// Ensure _id is always present
		if (!isset($fields['_id'])) {
			$fields['_id'] = new TMongoFieldInfo([
				'FieldName' => '_id',
				'BsonType' => 'objectId',
				'Required' => false,
				'Description' => [],
			]);
		}

		// 3. Fetch indexes via listIndexes
		$indexes = [];
		try {
			$idxCursor = $manager->executeCommand($db, new Command(['listIndexes' => $collectionName]));
			$idxCursor->setTypeMap(['root' => 'array', 'document' => 'array', 'array' => 'array']);
			$indexes = $idxCursor->toArray();
		} catch (\Exception $e) {
			// Collection may not exist yet — ignore
		}

		return new TMongoCollectionInfo($collectionName, $fields, $indexes, $jsonSchema);
	}

	/**
	 * Parses a $jsonSchema properties map into an array of TMongoFieldInfo objects.
	 * @param array $jsonSchema the $jsonSchema document.
	 * @return TMongoFieldInfo[] field info objects keyed by field name.
	 */
	protected function parseSchemaFields(array $jsonSchema): array
	{
		$fields = [];
		$required = $jsonSchema['required'] ?? [];
		$properties = $jsonSchema['properties'] ?? [];

		foreach ($properties as $fieldName => $schema) {
			$bsonType = $schema['bsonType'] ?? $schema['type'] ?? 'string';
			// bsonType may be an array of types (e.g. ['string', 'null']); take the first
			if (is_array($bsonType)) {
				$bsonType = $bsonType[0];
			}
			$fields[$fieldName] = new TMongoFieldInfo([
				'FieldName' => $fieldName,
				'BsonType' => (string) $bsonType,
				'Required' => in_array($fieldName, $required, true),
				'Description' => $schema,
			]);
		}

		return $fields;
	}

	/**
	 * Infers field information from a sample document.
	 * @param array $document a sample document from the collection.
	 * @return TMongoFieldInfo[] field info objects keyed by field name.
	 */
	protected function inferFieldsFromDocument(array $document): array
	{ // new
		$fields = [];
		foreach ($document as $fieldName => $value) {
			$bsonType = $this->inferBsonType($value);
			$fields[$fieldName] = new TMongoFieldInfo([
				'FieldName' => $fieldName,
				'BsonType' => $bsonType,
				'Required' => false,
				'Description' => [],
			]);
		}
		return $fields;
	}

	/**
	 * Infers the BSON type from a PHP value.
	 * @param mixed $value the PHP value.
	 * @return string the BSON type.
	 */
	protected function inferBsonType($value): string
	{// new
		if ($value === null) {
			return 'null';
		}
		if (is_bool($value)) {
			return 'bool';
		}
		if (is_int($value)) {
			return 'int';
		}
		if (is_float($value)) {
			return 'double';
		}
		if (is_string($value)) {
			return 'string';
		}
		if ($value instanceof \MongoDB\BSON\ObjectId) {
			return 'objectId';
		}
		if ($value instanceof \MongoDB\BSON\UTCDateTime) {
			return 'date';
		}
		if ($value instanceof \MongoDB\BSON\Decimal128) {
			return 'decimal';
		}
		if ($value instanceof \MongoDB\BSON\Int64) {
			return 'long';
		}
		if (is_array($value)) {
			return 'array';
		}
		if (is_object($value)) {
			return 'object';
		}
		return 'string';
	}

	/**
	 * Returns all collection names in the configured database.
	 * @throws TDbException if the driver command fails.
	 * @return string[] the collection names.
	 */
	public function findCollectionNames(): array
	{
		$db = $this->_connection->getDatabaseName();
		$manager = $this->_connection->getManager();

		$cursor = $manager->executeCommand($db, new Command(['listCollections' => 1, 'nameOnly' => true]));
		$cursor->setTypeMap(['root' => 'array', 'document' => 'array', 'array' => 'array']);

		$names = [];
		foreach ($cursor as $info) {
			$names[] = $info['name'];
		}
		return $names;
	}

}

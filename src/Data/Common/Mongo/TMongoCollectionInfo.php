<?php

/**
 * TMongoCollectionInfo class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Data\Common\Mongo;

use Prado\Data\IDataConnection;
use Prado\Data\Common\IDataTableInfo;
use Prado\Data\Common\IDataCommandBuilder;

/**
 * TMongoCollectionInfo provides schema and index metadata for a MongoDB collection.
 *
 * It is the MongoDB analogue of {@see \Prado\Data\Common\TDbTableInfo}.
 * Schema information is derived from the collection's JSON Schema validator
 * (configured via the `$jsonSchema` validator operator) and from the collection's
 * index list, both retrieved by {@see TMongoMetaData}.
 *
 * Because MongoDB is schemaless by default, both {@see getFields} and
 * {@see getIndexes} may be empty if no validator or indexes are defined.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 1.0.0
 */
class TMongoCollectionInfo extends \Prado\TComponent implements \Prado\Data\Common\IDataTableInfo
{
	private string $_collectionName;

	/** @var TMongoFieldInfo[] fields keyed by field name. */
	private array $_fields;

	/** @var array raw index descriptor arrays from listIndexes. */
	private array $_indexes;

	/** @var array the raw $jsonSchema document from the validator, if any. */
	private array $_validationSchema;

	/**
	 * Constructor.
	 * @param string $name the collection name.
	 * @param TMongoFieldInfo[] $fields field info objects keyed by field name.
	 * @param array $indexes raw index descriptor arrays from listIndexes.
	 * @param array $validationSchema the $jsonSchema portion of the collection validator.
	 */
	public function __construct(string $name, array $fields = [], array $indexes = [], array $validationSchema = [])
	{
		$this->_collectionName = $name;
		$this->_fields = $fields;
		$this->_indexes = $indexes;
		$this->_validationSchema = $validationSchema;
		parent::__construct();
	}

	/**
	 * @return string the collection name.
	 */
	public function getCollectionName(): string
	{
		return $this->_collectionName;
	}

	/**
	 * @return string the unqualified collection name.
	 */
	public function getTableName(): string
	{
		return $this->_collectionName;
	}

	/**
	 * Returns the fully-qualified collection name.
	 *
	 * MongoDB has no schema prefix (unlike SQL schemas), so this returns the
	 * same value as {@see getTableName}.
	 *
	 * @return string the collection name.
	 */
	public function getTableFullName(): string
	{
		return $this->_collectionName;
	}

	/**
	 * @return bool always false — MongoDB collections are not views.
	 */
	public function getIsView(): bool
	{
		return false;
	}

	/**
	 * Returns all known fields, keyed by field name.
	 *
	 * Fields are derived from the collection's JSON Schema validator. If no
	 * validator is configured, only the implicit `_id` field is returned.
	 *
	 * @return TMongoFieldInfo[] the field info objects.
	 */
	public function getFields(): array
	{
		return $this->_fields;
	}

	/**
	 * Returns the field info for a specific field, or null if not found.
	 * @param string $name the field name.
	 * @return null|TMongoFieldInfo the field info, or null.
	 */
	public function getField($name): ?TMongoFieldInfo
	{
		return $this->_fields[$name] ?? null;
	}

	/**
	 * Returns the field names defined in the schema.
	 * @return string[] the field names.
	 */
	public function getFieldNames(): array
	{
		return array_keys($this->_fields);
	}

	// -----------------------------------------------------------------------
	// IDataTableInfo — SQL-style column aliases (map to MongoDB field concepts)
	// -----------------------------------------------------------------------

	/**
	 * Returns all field info objects keyed by field name.
	 *
	 * Alias for {@see getFields} that satisfies the SQL-centric
	 * {@see IDataTableInfo::getColumns()} contract.
	 *
	 * @return TMongoFieldInfo[] the field info objects.
	 */
	public function getColumns(): array
	{
		return $this->_fields;
	}

	/**
	 * Returns the field info for a specific field, or null if not found.
	 *
	 * Alias for {@see getField} that satisfies the SQL-centric
	 * {@see IDataTableInfo::getColumn()} contract.
	 *
	 * @param string $name the field name.
	 * @return null|TMongoFieldInfo the field info, or null.
	 */
	public function getColumn($name): ?TMongoFieldInfo
	{
		return $this->_fields[$name] ?? null;
	}

	/**
	 * Returns the names of all fields known for this collection.
	 *
	 * Alias for {@see getFieldNames} that satisfies the SQL-centric
	 * {@see IDataTableInfo::getColumnNames()} contract.
	 *
	 * @return string[] the field names.
	 */
	public function getColumnNames(): array
	{
		return array_keys($this->_fields);
	}

	/**
	 * Returns the primary-key field names.
	 *
	 * MongoDB always uses `_id` as its implicit primary key.
	 *
	 * @return string[] always `['_id']`.
	 */
	public function getPrimaryKeys(): array
	{
		return ['_id'];
	}

	/**
	 * Returns foreign-key descriptors.
	 *
	 * MongoDB does not enforce foreign-key relationships at the database level,
	 * so this always returns an empty array.
	 *
	 * @return array always `[]`.
	 */
	public function getForeignKeys(): array
	{
		return [];
	}

	/**
	 * Returns the raw index descriptors from `listIndexes`.
	 * Each element is an associative array with at minimum a `key` sub-document
	 * and a `name` string.
	 * @return array the index descriptors.
	 */
	public function getIndexes(): array
	{
		return $this->_indexes;
	}

	/**
	 * Returns the raw `$jsonSchema` document from the collection validator, if any.
	 * @return array the JSON Schema document, or an empty array if none.
	 */
	public function getValidationSchema(): array
	{
		return $this->_validationSchema;
	}

	/**
	 * Creates a command builder for this collection.
	 * @param IDataConnection $connection the connection to use.
	 * @return IDataCommandBuilder a new command builder.
	 */
	public function createCommandBuilder(IDataConnection $connection): IDataCommandBuilder
	{
		return new TMongoCommandBuilder($connection, $this);
	}
}

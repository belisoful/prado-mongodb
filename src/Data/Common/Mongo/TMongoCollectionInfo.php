<?php

/**
 * TMongoCollectionInfo class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Data\Common\Mongo;

use Prado\Data\TMongoConnection;

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
 * @since 4.3.3
 */
class TMongoCollectionInfo extends \Prado\TComponent
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
	public function getField(string $name): ?TMongoFieldInfo
	{
		return $this->_fields[$name] ?? null;
	}

	/**
	 * Returns the field names defined in the schema.
	 * @return array the field names.
	 */
	public function getFieldNames(): array
	{
		return array_keys($this->_fields);
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
	 * @param TMongoConnection $connection the connection to use.
	 * @return TMongoCommandBuilder a new command builder.
	 */
	public function createCommandBuilder(TMongoConnection $connection): TMongoCommandBuilder
	{
		return new TMongoCommandBuilder($connection, $this);
	}
}

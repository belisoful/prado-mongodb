<?php

/**
 * TMongoFieldInfo class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Data\Common\Mongo;

/**
 * TMongoFieldInfo describes a single field within a MongoDB collection.
 *
 * It is the MongoDB analogue of {@see \Prado\Data\Common\TDbTableColumn} and
 * carries metadata derived from the collection's JSON Schema validator
 * (if one is configured) or from field-level analysis.
 *
 * Because MongoDB is schemaless, fields are not guaranteed to be present in
 * every document. {@see getIsRequired} reflects whether the JSON Schema
 * validator marks the field as required.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 1.0.0
 */
class TMongoFieldInfo extends \Prado\TComponent
{
	/**
	 * Maps BSON type names to PHP primitive types.
	 */
	private static array $_bsonToPhp = [
		'double' => 'float',
		'string' => 'string',
		'object' => 'array',
		'array' => 'array',
		'binData' => 'string',
		'objectId' => 'string',
		'bool' => 'boolean',
		'date' => 'string',
		'null' => 'string',
		'regex' => 'string',
		'javascript' => 'string',
		'int' => 'integer',
		'timestamp' => 'integer',
		'long' => 'integer',
		'decimal' => 'float',
		'number' => 'float',
	];

	private array $_info;

	/**
	 * Constructor.
	 * @param array $info an associative array of field metadata with keys:
	 *   - FieldName (string)
	 *   - BsonType  (string, default 'string')
	 *   - Required  (bool, default false)
	 *   - Description (array, optional raw JSON Schema sub-document)
	 */
	public function __construct(array $info)
	{
		$this->_info = $info;
		parent::__construct();
	}

	/**
	 * @param string $key the metadata key.
	 * @param mixed $default default value if the key is absent.
	 * @return mixed the metadata value.
	 */
	protected function getInfo(string $key, mixed $default = null): mixed
	{
		return $this->_info[$key] ?? $default;
	}

	/**
	 * @return string the field name.
	 */
	public function getFieldName(): string
	{
		return (string) $this->getInfo('FieldName', '');
	}

	/**
	 * Returns the BSON type of this field as declared in the JSON Schema validator.
	 * Common values: 'string', 'int', 'long', 'double', 'decimal', 'bool',
	 * 'date', 'objectId', 'array', 'object', 'binData'.
	 * @return string the BSON type name.
	 */
	public function getBsonType(): string
	{
		return (string) $this->getInfo('BsonType', 'string');
	}

	/**
	 * Returns the PHP primitive type that best represents this field's BSON type.
	 * @return string one of 'string', 'integer', 'float', 'boolean', 'array'.
	 */
	public function getPHPType(): string
	{
		$bsonType = strtolower($this->getBsonType());
		return self::$_bsonToPhp[$bsonType] ?? 'string';
	}

	/**
	 * @return bool whether the JSON Schema validator marks this field as required.
	 */
	public function getIsRequired(): bool
	{
		return (bool) $this->getInfo('Required', false);
	}

	/**
	 * @return bool whether this is the document identity field (_id).
	 */
	public function getIsId(): bool
	{
		return $this->getFieldName() === '_id';
	}

	/**
	 * @return array the raw JSON Schema sub-document for this field, if available.
	 */
	public function getDescription(): array
	{
		return (array) $this->getInfo('Description', []);
	}
}

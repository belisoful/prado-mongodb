<?php

/**
 * TMongoQueryCriteria class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Data\DataGateway;

use Prado\Collections\TAttributeCollection;
use Prado\Exceptions\TInvalidDataTypeException;
use Traversable;

/**
 * TMongoQueryCriteria is the MongoDB analogue of {@see TSqlCriteria}.  It
 * carries a MongoDB filter document as the native predicate, together with the
 * portable fields defined by {@see IDataCriteria} (parameters, ordering, limit,
 * offset, select).  Returned by {@see \Prado\Data\Common\Mongo\TMongoCommandBuilder}
 * factories and consumed by the same builder when composing find/count/update/
 * delete commands.
 *
 * The class implements {@see IDataCriteria} directly and is not a
 * {@see TSqlCriteria}: a MongoDB criteria has no SQL condition.  ActiveRecord
 * has passed any {@see IDataCriteria} through to the command builder, so the
 * filter reaches {@see \Prado\Data\Common\Mongo\TMongoCommandBuilder} intact.
 *
 * Usage:
 * ```php
 * $criteria = new TMongoQueryCriteria(['status' => 'active']);
 * $criteria->OrdersBy['created_at'] = 'desc';
 * $criteria->Limit = 10;
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 1.0.0
 */
class TMongoQueryCriteria extends \Prado\TComponent implements IDataCriteria
{
	private array $_filter;
	private TAttributeCollection $_parameters;
	private TAttributeCollection $_ordersBy;
	private mixed $_select = '*';
	private int $_limit = -1;
	private int $_offset = -1;

	/**
	 * @param array $filter MongoDB filter document.
	 */
	public function __construct(array $filter = [])
	{
		$this->_filter = $filter;
		$this->_parameters = new TAttributeCollection();
		$this->_parameters->setCaseSensitive(true);
		$this->_ordersBy = new TAttributeCollection();
		$this->_ordersBy->setCaseSensitive(true);
		parent::__construct();
	}

	/**
	 * Copies the parameter and ordering collections, so a clone of the criteria
	 * (ActiveRecord has cloned the criteria it is given) does not share them.
	 */
	public function __clone()
	{
		$this->_parameters = clone $this->_parameters;
		$this->_ordersBy = clone $this->_ordersBy;
		parent::__clone();
	}

	/**
	 * @return array the MongoDB filter document.
	 */
	public function getFilter(): array
	{
		return $this->_filter;
	}

	/**
	 * @param array $value the MongoDB filter document.
	 */
	public function setFilter(array $value): void
	{
		$this->_filter = $value;
	}

	/**
	 * @return TAttributeCollection the name-value parameters; unused by the MongoDB
	 *   builder, which takes its values from the {@see getFilter Filter} document.
	 */
	public function getParameters()
	{
		return $this->_parameters;
	}

	/**
	 * @param array|\ArrayAccess $value the name-value parameters.
	 * @throws TInvalidDataTypeException when the value is not an array or an `ArrayAccess`.
	 */
	public function setParameters($value): void
	{
		if (!(is_array($value) || $value instanceof \ArrayAccess)) {
			throw new TInvalidDataTypeException('mongoquerycriteria_parameters_invalid', get_debug_type($value));
		}
		$this->_parameters->copyFrom($value);
	}

	/**
	 * @return TAttributeCollection the ordering as field => direction (`asc` or `desc`).
	 */
	public function getOrdersBy()
	{
		return $this->_ordersBy;
	}

	/**
	 * @param array|string|Traversable $value the ordering as field => direction pairs,
	 *   or a string such as `"name asc, age desc"`.
	 */
	public function setOrdersBy($value): void
	{
		if (is_array($value) || $value instanceof Traversable) {
			$this->_ordersBy->copyFrom($value);
			return;
		}
		$orders = [];
		foreach (explode(',', trim(preg_replace('/\s+/', ' ', (string) $value))) as $order) {
			$parts = explode(' ', trim($order));
			if ($parts[0] !== '') {
				$orders[$parts[0]] = $parts[1] ?? 'asc';
			}
		}
		$this->_ordersBy->copyFrom($orders);
	}

	/**
	 * @return mixed the field selection; `'*'` selects every field.
	 */
	public function getSelect()
	{
		return $this->_select;
	}

	/**
	 * @param mixed $value the field selection; `'*'` selects every field.
	 */
	public function setSelect($value): void
	{
		$this->_select = $value;
	}

	/**
	 * @return int row limit; -1 means no limit.
	 */
	public function getLimit()
	{
		return $this->_limit;
	}

	/**
	 * @param int $value row limit; -1 means no limit.
	 */
	public function setLimit($value): void
	{
		$this->_limit = $value === null ? -1 : (int) $value;
	}

	/**
	 * @return int row offset; -1 means no offset.
	 */
	public function getOffset()
	{
		return $this->_offset;
	}

	/**
	 * @param int $value row offset; -1 means no offset.
	 */
	public function setOffset($value): void
	{
		$this->_offset = $value === null ? -1 : (int) $value;
	}

	/**
	 * @return string the filter document as JSON, followed by the ordering,
	 *   limit and offset when they are set.
	 */
	public function __toString()
	{
		$str = (string) json_encode($this->_filter);
		$orders = [];
		foreach ($this->getOrdersBy() as $k => $v) {
			$orders[] = "{$k} => {$v}";
		}
		if (count($orders) > 0) {
			$str .= ', "' . implode(', ', $orders) . '"';
		}
		if ($this->_limit >= 0) {
			$str .= ', ' . $this->_limit;
		}
		if ($this->_offset >= 0) {
			$str .= ', ' . $this->_offset;
		}
		return $str;
	}
}

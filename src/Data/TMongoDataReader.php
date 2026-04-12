<?php

/**
 * TMongoDataReader class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Data;

use MongoDB\Driver\Cursor;
use Prado\Exceptions\TDbException;

/**
 * TMongoDataReader represents a buffered, forward-only stream of documents from a MongoDB query.
 *
 * TMongoDataReader is usually obtained by calling {@see TMongoCommand::query},
 * {@see TMongoCommand::findMany}, or {@see TMongoCommand::aggregate}.
 * It implements {@see IDataReader} to provide a consistent API alongside the SQL
 * layer ({@see TDbDataReader}).
 *
 * All documents are eagerly loaded from the driver cursor into an internal array
 * when the reader is constructed. This means:
 *
 * - {@see getRowCount} is always accurate.
 * - The cursor is released immediately after construction.
 * - For very large result sets, prefer streaming directly over the cursor.
 *
 * The reader can be consumed either via the {@see read} method or with a
 * standard `foreach` loop (PHP Iterator). Like {@see TDbDataReader}, `foreach`
 * can only be used **once** — a second `foreach` will throw an exception.
 *
 * ```php
 * $reader = $conn->createCommand('users')->findMany(['active' => true]);
 *
 * // Option 1: foreach (single-pass)
 * foreach ($reader as $doc) {
 *     echo $doc['name'];
 * }
 *
 * // Option 2: read all at once
 * $docs = $reader->readAll();
 *
 * // Option 3: step-by-step
 * while (($doc = $reader->read()) !== false) {
 *     echo $doc['name'];
 * }
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 4.3.3
 */
class TMongoDataReader extends \Prado\TComponent implements IDataReader
{
	/** @var array Buffered result documents (all loaded eagerly from the cursor). */
	private array $_rows;

	private bool $_closed = false;

	/**
	 * Current read position for the {@see read}/{@see readAll} API.
	 * Advances independently of the Iterator position.
	 */
	private int $_readPos = 0;

	/**
	 * Current Iterator position. Starts at -1 (not yet rewound).
	 * Set to 0 on first {@see rewind}; throws on subsequent calls.
	 */
	private int $_index = -1;

	/** Current row for the Iterator API. */
	private mixed $_current = false;

	/**
	 * Constructor.
	 * @param TMongoCommand $_command the command that produced this result (retained for event context).
	 * @param Cursor $cursor the driver cursor; its documents are buffered immediately.
	 */
	public function __construct(private TMongoCommand $_command, Cursor $cursor)
	{
		// Buffer eagerly: releases the cursor and enables getRowCount().
		$raw = $cursor->toArray();
		$this->_rows = array_map(static fn ($doc) => (array) $doc, $raw);
		parent::__construct();
	}

	/**
	 * @return TMongoCommand the command that produced this reader.
	 */
	public function getCommand(): TMongoCommand
	{
		return $this->_command;
	}

	// -----------------------------------------------------------------------
	// IDataReader — sequential read API
	// -----------------------------------------------------------------------

	/**
	 * Reads the next document and advances the read position.
	 *
	 * The read position is independent of the Iterator position; do not mix
	 * {@see read}/{@see readAll} with `foreach` on the same reader instance.
	 *
	 * @return array|false the next document as an associative array, or false if exhausted.
	 */
	public function read(): array|false
	{
		return $this->_rows[$this->_readPos++] ?? false;
	}

	/**
	 * Returns all remaining documents (from the current read position) as an array,
	 * then advances the read position to the end.
	 * @return array the remaining documents.
	 */
	public function readAll(): array
	{
		$result = array_slice($this->_rows, $this->_readPos);
		$this->_readPos = count($this->_rows);
		return $result;
	}

	/**
	 * Closes the reader.  After closing, {@see read} will return false.
	 */
	public function close(): void
	{
		$this->_closed = true;
	}

	/**
	 * @return bool whether this reader has been closed.
	 */
	public function getIsClosed(): bool
	{
		return $this->_closed;
	}

	/**
	 * @return int the total number of documents in the result set.
	 * Unlike {@see TDbDataReader}, this is always accurate because all documents
	 * are buffered at construction time.
	 */
	public function getRowCount(): int
	{
		return count($this->_rows);
	}

	// -----------------------------------------------------------------------
	// Iterator interface
	// -----------------------------------------------------------------------

	/**
	 * Resets the iterator to the initial state.
	 * This method is required by the Iterator interface.
	 * @throws TDbException if called more than once (forward-only, like {@see TDbDataReader}).
	 */
	public function rewind(): void
	{
		if ($this->_index < 0) {
			$this->_current = $this->_rows[0] ?? false;
			$this->_index = 0;
		} else {
			throw new TDbException('dbdatareader_rewind_invalid');
		}
	}

	/**
	 * Returns the zero-based index of the current row.
	 * @return int the current row index.
	 */
	#[\ReturnTypeWillChange]
	public function key()
	{
		return $this->_index;
	}

	/**
	 * Returns the current document.
	 * @return mixed the current document array.
	 */
	#[\ReturnTypeWillChange]
	public function current()
	{
		return $this->_current;
	}

	/**
	 * Advances the iterator to the next document.
	 */
	public function next(): void
	{
		$this->_index++;
		$this->_current = $this->_rows[$this->_index] ?? false;
	}

	/**
	 * @return bool whether the iterator is positioned on a valid document.
	 */
	public function valid(): bool
	{
		return $this->_current !== false;
	}
}

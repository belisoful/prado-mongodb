<?php

/**
 * TMongoTransaction class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Data;

use MongoDB\Driver\Session;
use Prado\Exceptions\TDbException;
use Prado\TPropertyValue;

/**
 * TMongoTransaction represents a MongoDB multi-document transaction.
 *
 * TMongoTransaction is usually created by calling {@see TMongoConnection::beginTransaction}.
 * It wraps a {@see \MongoDB\Driver\Session} on which a transaction has already been started.
 *
 * Multi-document transactions require a replica set or mongos topology and
 * MongoDB 4.0 or later. On standalone deployments or versions prior to 4.0,
 * calling {@see TMongoConnection::beginTransaction} will throw a driver exception.
 *
 * While a transaction is active, {@see TMongoConnection::getCurrentTransaction}
 * returns this object and all commands created via {@see TMongoConnection::createCommand}
 * will automatically include the session so that they participate in the transaction.
 *
 * ```php
 * $tx = $conn->beginTransaction();
 * try {
 *     $conn->createCommand('orders')->insertOne(['item' => 'widget', 'qty' => 1]);
 *     $conn->createCommand('inventory')->updateOne(
 *         ['item' => 'widget'],
 *         ['$inc' => ['qty' => -1]]
 *     );
 *     $tx->commit();
 * } catch (\Exception $e) {
 *     $tx->rollback();
 * }
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 4.3.3
 */
class TMongoTransaction extends \Prado\TComponent implements IDataTransaction
{
	private TMongoConnection $_connection;
	private Session $_session;
	private bool $_active;

	/**
	 * Constructor.
	 * @param TMongoConnection $connection the connection that started this transaction.
	 * @param Session $session the driver session with an already-started transaction.
	 */
	public function __construct(TMongoConnection $connection, Session $session)
	{
		$this->_connection = $connection;
		$this->_session = $session;
		$this->_active = true;
		parent::__construct();
	}

	/**
	 * Commits the transaction.
	 * @throws TDbException if the transaction is not active.
	 */
	public function commit(): void
	{
		if ($this->_active && $this->_connection->getActive()) {
			$this->_session->commitTransaction();
			$this->_active = false;
		} else {
			throw new TDbException('mongotransaction_inactive');
		}
	}

	/**
	 * Rolls back (aborts) the transaction.
	 * @throws TDbException if the transaction is not active.
	 */
	public function rollback(): void
	{
		if ($this->_active && $this->_connection->getActive()) {
			$this->_session->abortTransaction();
			$this->_active = false;
		} else {
			throw new TDbException('mongotransaction_inactive');
		}
	}

	/**
	 * @return bool whether the transaction is currently active.
	 */
	public function getActive(): bool
	{
		return $this->_active;
	}

	/**
	 * @return TMongoConnection the MongoDB connection for this transaction.
	 */
	public function getConnection(): TMongoConnection
	{
		return $this->_connection;
	}

	/**
	 * Returns the underlying driver session. Used internally by {@see TMongoCommand}
	 * to attach the session to each operation while the transaction is active.
	 * @return Session the MongoDB driver session.
	 */
	public function getSession(): Session
	{
		return $this->_session;
	}
}

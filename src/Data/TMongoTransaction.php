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
use Prado\Data\Common\Mongo\TMongoMetaData;
use Prado\Data\TDataTransaction;
use Prado\Exceptions\TDbException;

/**
 * TMongoTransaction represents a MongoDB multi-document transaction.
 *
 * Extends {@see TDataTransaction} (the driver-agnostic transaction token)
 * and wraps a {@see \MongoDB\Driver\Session} on which a transaction has
 * already been started.  Created by {@see TMongoConnection::beginTransaction}.
 *
 * Multi-document transactions require a replica set or mongos topology and
 * MongoDB 4.0 or later.  On standalone deployments or versions prior to 4.0,
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
 * @since 1.0.0
 */
class TMongoTransaction extends TDataTransaction
{
	private Session $_session;

	/**
	 * @param TMongoConnection $connection the connection that started this transaction.
	 * @param Session $session the driver session with an already-started transaction.
	 */
	public function __construct(TMongoConnection $connection, Session $session)
	{
		$this->_session = $session;
		parent::__construct($connection);
	}

	/**
	 * Commits the transaction.  Ends the underlying driver session after the
	 * commit succeeds, then raises {@see onCommit OnCommit}, as
	 * {@see TDbConnection::commit} does for SQL transactions.
	 * @throws TDbException if the transaction is not active.
	 */
	public function commit(): void
	{
		if (!$this->getActive() || !$this->getConnection()->getActive()) {
			throw new TDbException('mongotransaction_inactive');
		}
		$this->_session->commitTransaction();
		$this->_session->endSession();
		$this->deactivate();
		$this->onCommit();
	}

	/**
	 * Rolls back (aborts) the transaction.  Ends the underlying driver session
	 * after the abort completes, then raises {@see onRollback OnRollback}, as
	 * {@see TDbConnection::rollback} does for SQL transactions.
	 * @throws TDbException if the transaction is not active.
	 */
	public function rollback(): void
	{
		if (!$this->getActive() || !$this->getConnection()->getActive()) {
			throw new TDbException('mongotransaction_inactive');
		}
		$this->_session->abortTransaction();
		$this->_session->endSession();
		$this->deactivate();
		$this->onRollback();
	}

	/**
	 * @return TMongoConnection the MongoDB connection for this transaction.
	 */
	public function getConnection(): TMongoConnection
	{
		return parent::getConnection();
	}

	/**
	 * Returns the underlying driver session.  Used internally by
	 * {@see TMongoCommand} to attach the session to each operation while the
	 * transaction is active.
	 * @return Session the MongoDB driver session.
	 */
	public function getSession(): Session
	{
		return $this->_session;
	}

	/**
	 * Creates a command for the given collection on this transaction's
	 * connection.  Convenience shorthand for
	 * `$transaction->getConnection()->createCommand($collection)`.
	 *
	 * @param mixed $collection the collection name.
	 * @return TMongoCommand the new command object.
	 */
	public function createCommand($collection): TMongoCommand
	{
		return $this->getConnection()->createCommand($collection);
	}

	/**
	 * Returns the metadata helper for this transaction's connection.
	 * Convenience shorthand for `$transaction->getConnection()->getDbMetaData()`.
	 *
	 * @return TMongoMetaData the metadata helper.
	 */
	public function getDbMetaData(): TMongoMetaData
	{
		return $this->getConnection()->getDbMetaData();
	}

	/**
	 * Starts a new transaction on this transaction's connection, reactivating
	 * this transaction object for a new work unit.
	 *
	 * Allows a single {@see TMongoTransaction} instance to span multiple
	 * sequential work units without allocating a new object each time:
	 *
	 * ```php
	 * $tx = $conn->beginTransaction();
	 * $tx->commit();
	 * // ...
	 * $tx->beginTransaction(); // reuse the same object
	 * $tx->commit();
	 * ```
	 *
	 * **Supersession guard:** {@see TMongoConnection::beginTransaction()}
	 * always allocates a new transaction object.  If it has been called since
	 * this transaction completed, this object is superseded — the connection
	 * now owns a newer transaction.  Calling {@see beginTransaction()} on a
	 * superseded object throws a {@see TDbException}.
	 *
	 * @throws TDbException if already active, if the connection is not active,
	 *   or if this transaction has been superseded by a newer one.
	 * @return static
	 */
	public function beginTransaction(): static
	{
		if ($this->getActive()) {
			throw new TDbException('dbconnection_active_transaction');
		}
		$conn = $this->getConnection();
		if (!$conn->getActive()) {
			throw new TDbException('mongoconnection_connection_inactive');
		}
		if ($conn->getLastTransaction() !== $this) {
			throw new TDbException('dbtransaction_transaction_superseded');
		}
		$this->_session->startTransaction();
		$this->activate();
		return $this;
	}
}

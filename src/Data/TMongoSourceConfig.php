<?php

/**
 * TMongoSourceConfig class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Data;

use Prado\Data\ActiveRecord\Scaffold\InputBuilder\TMongoScaffoldInput;
use Prado\Data\Common\Mongo\TMongoMetaData;
use Prado\Exceptions\TConfigurationException;

/**
 * TMongoSourceConfig module class provides `<module>` configuration for
 * MongoDB connections in a PRADO application.
 *
 * Example usage in application.xml:
 * ```xml
 * <modules>
 *     <module id="mongodb" class="Prado\Data\TMongoSourceConfig">
 *         <database ConnectionString="mongodb://localhost:27017"
 *             DatabaseName="mydb" />
 *     </module>
 * </modules>
 * ```
 *
 * Usage in PHP:
 * ```php
 * $conn = $this->Application->Modules['mongodb']->DbConnection;
 * $conn->createCommand('users')->findMany(['active' => true]);
 * ```
 *
 * The default connection class is set to {@see TMongoConnection}.
 * Set {@see setConnectionClass} to supply a custom subclass.
 *
 * This module also registers two global event handlers so that PRADO
 * framework components that are unaware of MongoDB can discover the correct
 * metadata and scaffold input builder for a MongoDB connection:
 *
 * - **fxDataGetMetaDataInstance** — returns a {@see TMongoMetaData} when the
 *   framework's metadata factory cannot find a built-in driver handler.
 * - **fxActiveRecordCreateScaffoldInput** — returns a
 *   {@see TMongoScaffoldInput} for scaffold generation against a MongoDB
 *   connection.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 1.0.0
 */
class TMongoSourceConfig extends \Prado\Data\TDataSourceConfig
{
	/**
	 * Initialises the module and sets the default connection class to
	 * {@see TMongoConnection} before the parent reads the XML configuration.
	 *
	 * @param mixed $config the XML configuration element (unused here).
	 */
	public function dyPreInit($config): void
	{
		parent::setConnectionClass(TMongoConnection::class);
	}

	/**
	 * Alias for {@see getDbConnection()}.
	 *
	 * @return TMongoConnection the MongoDB connection instance.
	 */
	public function getDatabase(): TMongoConnection
	{
		return $this->getDbConnection();
	}

	/**
	 * Resolves a MongoDB connection from a module ID.
	 *
	 * Looks up the module and returns a {@see TMongoConnection} either
	 * directly or via its {@see TDataSourceConfig::getDbConnection()} accessor.
	 *
	 * @param string $id the application module ID.
	 * @throws TConfigurationException if the module is not a valid MongoDB connection.
	 * @return TMongoConnection the resolved connection.
	 */
	protected function findConnectionByID(string $id): TMongoConnection
	{
		$conn = $this->getApplication()->getModule($id);
		if ($conn instanceof TDataSourceConfig) {
			$conn = $conn->getDbConnection();
		}
		if ($conn instanceof TMongoConnection) {
			return $conn;
		}
		throw new TConfigurationException('datasource_dbconnection_invalid', $id);
	}

	/**
	 * Global event handler — provides a {@see TMongoMetaData} instance when
	 * the PRADO framework's metadata factory cannot find a driver handler.
	 *
	 * Raised as `fxDataGetMetaDataInstance` on the application event bus.
	 * Returns null for non-MongoDB connections, allowing other handlers to run.
	 *
	 * @param string $sender the static class raising the event.
	 * @param IDataConnection|mixed $param the connection whose metadata is needed.
	 * @return TMongoMetaData|null the metadata instance, or null if not applicable.
	 */
	public function fxDataGetMetaDataInstance(string $sender, mixed $param): ?TMongoMetaData
	{
		if (!($param instanceof IDataConnection)) {
			return null;
		}
		if (strtolower($param->getDriverName()) !== TMongoConnection::DRIVER_NAME) {
			return null;
		}
		return new TMongoMetaData($param);
	}

	/**
	 * Global event handler — provides a {@see TMongoScaffoldInput} when the
	 * PRADO ActiveRecord scaffold system cannot find a built-in input builder
	 * for a MongoDB connection.
	 *
	 * Raised as `fxActiveRecordCreateScaffoldInput` on the application event bus.
	 * Returns null for non-MongoDB connections.
	 *
	 * @param mixed $sender the object raising the event.
	 * @param IDataConnection|mixed $param the connection being scaffolded.
	 * @return TMongoScaffoldInput|null the scaffold input builder, or null if not applicable.
	 */
	public function fxActiveRecordCreateScaffoldInput(mixed $sender, mixed $param): ?TMongoScaffoldInput
	{
		if (!($param instanceof IDataConnection)) {
			return null;
		}
		if (strtolower($param->getDriverName()) !== TMongoConnection::DRIVER_NAME) {
			return null;
		}
		return new TMongoScaffoldInput();
	}
}

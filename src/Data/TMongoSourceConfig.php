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
 * - **fxDataGetMetaDataClass** — returns the {@see TMongoMetaData} class name
 *   when the framework's metadata factory cannot find a built-in driver handler.
 * - **fxActiveRecordScaffoldInputClass** — returns the {@see TMongoScaffoldInput}
 *   class name for scaffold generation against a MongoDB connection.
 *
 * Both events follow the framework contract: the connection is the event sender,
 * the driver name is the parameter, and the handler returns a class-name string
 * that the framework instantiates.
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
	protected function findConnectionByID($id): TMongoConnection
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
	 * Global event handler — provides the {@see TMongoMetaData} class name when
	 * the PRADO framework's metadata factory cannot find a built-in driver handler.
	 *
	 * Raised as `fxDataGetMetaDataClass` on the application event bus, with the
	 * connection as the sender and the driver name as the parameter.  The
	 * framework instantiates the returned class with the connection.  Returns
	 * null for non-MongoDB drivers, allowing other handlers to run.
	 *
	 * @param mixed $sender the connection raising the event.
	 * @param mixed $param the driver name of the connection.
	 * @return ?string the TMongoMetaData class name, or null if not applicable.
	 */
	public function fxDataGetMetaDataClass(mixed $sender, mixed $param): ?string
	{
		if (strtolower((string) $param) !== TMongoConnection::DRIVER_NAME) {
			return null;
		}
		return TMongoMetaData::class;
	}

	/**
	 * Global event handler — provides the {@see TMongoScaffoldInput} class name
	 * when the PRADO ActiveRecord scaffold system cannot find a built-in input
	 * builder for a MongoDB connection.
	 *
	 * Raised as `fxActiveRecordScaffoldInputClass` on the application event bus,
	 * with the connection as the sender and the driver name as the parameter.
	 * Returns null for non-MongoDB drivers.
	 *
	 * @param mixed $sender the connection raising the event.
	 * @param mixed $param the driver name of the connection.
	 * @return ?string the TMongoScaffoldInput class name, or null if not applicable.
	 */
	public function fxActiveRecordScaffoldInputClass(mixed $sender, mixed $param): ?string
	{
		if (strtolower((string) $param) !== TMongoConnection::DRIVER_NAME) {
			return null;
		}
		return TMongoScaffoldInput::class;
	}
}

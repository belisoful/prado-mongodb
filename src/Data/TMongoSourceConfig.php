<?php

/**
 * TDataSourceConfig class file.
 *
 * @author Wei Zhuo <weizhuo[at]gmail[dot]com>
 * @link https://github.com/pradosoft/prado
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Data;

use Prado\Data\TMongoConnection;
use Prado\Exceptions\TConfigurationException;
use Prado\Prado;
use Prado\TApplication;

/**
 * TDataSourceConfig module class provides <module> configuration for database connections.
 *
 * Example usage: mysql connection
 * ```php
 * <modules>
 * 	<module id="db1">
 * 		<database ConnectionString="mysqli:host=localhost;dbname=test"
 * 			username="dbuser" password="dbpass" />
 * 	</module>
 * </modules>
 * ```
 *
 * Usage in php:
 * ```php
 * class Home extends TPage
 * {
 * 		function onLoad($param)
 * 		{
 * 			$db = $this->Application->Modules['db1']->DbConnection;
 * 			$db->createCommand('...'); //...
 * 		}
 * }
 * ```
 *
 * The properties of <connection> are those of the class TDbConnection.
 * Set {@see setConnectionClass} attribute for a custom database connection class
 * that extends the TDbConnection class.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 3.1
 */
class TMongoSourceConfig extends \Prado\Data\TDataSourceConfig
{
	private static bool $_installed;

	public function dyPreInit($config)
	{
		$this->setConnectionClass(TMongoConnection::class);
	}


	/**
	 * Alias for getDbConnection().
	 * @return \Prado\Data\TDbConnection database connection.
	 */
	public function getDatabase()
	{
		return $this->getDbConnection();
	}

	/**
	 * Finds the database connection instance from the Application modules.
	 * @param string $id Database connection module ID.
	 * @throws TConfigurationException when module is not of TDbConnection or TDataSourceConfig.
	 * @return \Prado\Data\TDbConnection database connection.
	 */
	protected function findConnectionByID($id)
	{
		$conn = $this->getApplication()->getModule($id);
		if ($conn instanceof TDataSourceConfig) {
			$conn = $conn->getDbConnection();
		}
		if ($conn instanceof TMongoConnection) {
			return $conn;
		} else {
			throw new TConfigurationException('datasource_dbconnection_invalid', $id);
		}
	}

	/**
	 * if {@see TDbMetaData::getInstance()} cannot find a driver it raises this
	 * global event to find the TDbMetaData for the IDbConnection in $param
	 * @param string $sender the static class raising this event.
	 * @param IDataConnection|mixed $param
	 * @return ?TDbMetaData
	 */
	public function fxGetMetaDataInstance($sender, $param)
	{
		if (!($param instanceof IDataConnection)) {
			return null;
		}
		if (strtolower($param->getDriverName()) !== 'mongo') {
			return null;
		}
		return new TMongoMetaData($param);
	}

	/**
	 * if {@see TScaffoldInputBase::createInputBuilder()} cannot find a driver
	 * it raises this global event to find the TDbMetaData for the IDbConnection
	 * in $param
	 * @param mixed $sender
	 * @param IDataConnection $param
	 * @return ?TScaffoldInputBase
	 */
	public function fxActiveRecordCreateScaffoldInput($sender, $param)
	{
		if (!($param instanceof IDataConnection)) {
			return null;
		}
		if (strtolower($param->getDriverName()) !== 'mongo') {
			return null;
		}
		return new TMongoScaffoldInput();
	}
}

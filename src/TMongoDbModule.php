<?php

/**
 * MainModule class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado-composer-extension
 * @license https://github.com/pradosoft/prado-composer-extension/blob/master/LICENSE
 */

namespace Belisoful\Modules;

use Prado\Data\ActiveRecord\Scaffold\InputBuilder\TMongoScaffoldInput;
use Prado\Data\ActiveRecord\Scaffold\InputBuilder\TScaffoldInputBase;
use Prado\Data\Common\Mongo\TMongoMetaData;
use Prado\Data\Common\TDbMetaData;
use Prado\Data\IDataConnection;
use Prado\Data\TMongoSourceConfig;

/**
 * TMongoDbModule class.
 *
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 1.0.0
 */
class TMongoDbModule extends TMongoSourceConfig
{
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
		if ($param->getDriverName() !== 'mongo') {
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
	public function fxActiveRecordScaffoldInput($sender, $param)
	{
		if (!($param instanceof IDataConnection)) {
			return null;
		}
		if ($param->getDriverName() !== 'mongo') {
			return null;
		}
		return new TMongoScaffoldInput();
	}
}

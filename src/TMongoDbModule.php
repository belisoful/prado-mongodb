<?php

/**
 * MainModule class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado-composer-extension
 * @license https://github.com/pradosoft/prado-composer-extension/blob/master/LICENSE
 */

namespace Belisoful\Modules;

use Prado\TPropertyValue;
use Prado\TModule;
use Prado\Data\Common\Mongo\TMongoMetaData;

/**
 * MainModule class.
 *
 * main example bootstrap module class
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 1.0.0
 */
class TMongoDbModule extends TModule
{
	/** @var null|string property A */
	private $_propertya;

	/**
	 * Initializes the module, call the parent:init.
	 * @param null|array|\Prado\Xml\TXmlElement $config
	 */
	public function init($config)
	{
		parent::init($config);
	}

	/**
	 * Initializes the module, call the parent:init.
	 * @param mixed $sender
	 * @param TDbConnection $param
	 * @return ?TDbMetaData 
	 */
	public function fxGetDbMetaDataInstance($sender, $param)
	{
		if (!($param instanceof TDbConnection))
		if ($param !== 'mongo') {
			return null;
		}
		return return new TMongoMetaData($param);
	}
}

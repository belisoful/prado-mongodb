<?php

use Prado\Data\Common\Mongo\TMongoMetaData;
use Prado\Data\Common\TDbMetaData;
use Prado\Data\TDbConnection;
use Prado\Data\TMongoConnection;
use Prado\Data\TMongoSourceConfig;

/**
 * Tests the metadata integration of TMongoSourceConfig.
 *
 * None of these tests opens a MongoDB connection.
 */
class TMongoSourceConfigTest extends PHPUnit\Framework\TestCase
{
	private ?TMongoSourceConfig $_config = null;

	protected function setUp(): void
	{
		if (!extension_loaded('mongodb')) {
			$this->markTestSkipped('The mongodb extension is not available');
		}
	}

	protected function tearDown(): void
	{
		if ($this->_config !== null) {
			$this->_config->unlisten();
			$this->_config = null;
		}
	}

	/**
	 * Returns a TMongoConnection that skips connecting when activated.
	 */
	private function createInactiveConnection(): TMongoConnection
	{
		return new class () extends TMongoConnection {
			public function setActive($value): void
			{
			}
		};
	}

	/**
	 * Listens a TMongoSourceConfig that counts its fxDataGetMetaDataClass calls.
	 */
	private function listenCountingConfig(): TMongoSourceConfig
	{
		$this->_config = new class () extends TMongoSourceConfig {
			public int $metaDataClassCalls = 0;

			public function fxDataGetMetaDataClass(mixed $sender, mixed $param): ?string
			{
				$this->metaDataClassCalls++;
				return parent::fxDataGetMetaDataClass($sender, $param);
			}
		};
		$this->_config->listen();
		return $this->_config;
	}

	// --- fxDataGetMetaDataClass ---

	public function test_metadata_class_for_mongo_driver(): void
	{
		$config = new TMongoSourceConfig();
		$conn = $this->createInactiveConnection();
		$this->assertSame(TMongoMetaData::class, $config->fxDataGetMetaDataClass($conn, $conn->getDriverName()));
	}

	public function test_metadata_class_null_for_other_driver(): void
	{
		$config = new TMongoSourceConfig();
		$this->assertNull($config->fxDataGetMetaDataClass(new TDbConnection(), 'mysql'));
	}

	public function test_db_metadata_get_instance_creates_plugin_metadata(): void
	{
		$config = $this->listenCountingConfig();
		$conn = $this->createInactiveConnection();

		$metaData = TDbMetaData::getInstance($conn);

		$this->assertSame(1, $config->metaDataClassCalls);
		$this->assertInstanceOf(TMongoMetaData::class, $metaData);
		$this->assertSame($conn, $metaData->getDbConnection());
	}
}

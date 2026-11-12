<?php

/**
 * ActiveRecordMongoTest — end-to-end integration test proving that the Prado
 * ActiveRecord stack (TActiveRecord → TActiveRecordGateway →
 * TDataGatewayCommand → TMongoCommandBuilder) works against a live MongoDB
 * connection as a drop-in replacement for a SQL/PDO connection.
 *
 * Unlike PortabilityTest (which asserts interface compliance and command
 * structure with no live DB), this test drives a real TActiveRecord subclass
 * through the full framework machinery against a running mongod, exercising
 * save / findByPk / findAll / count / update / delete.
 *
 * Requires a live MongoDB instance; skipped when the extension or server is
 * unavailable.
 */

use Prado\Data\ActiveRecord\TActiveRecord;
use Prado\Data\ActiveRecord\TActiveRecordManager;
use Prado\Data\TMongoConnection;

if (class_exists(TActiveRecord::class)) {
	/**
	 * Minimal ActiveRecord model backed by a MongoDB collection.
	 */
	class MongoArUser extends TActiveRecord
	{
		public const TABLE = 'ar_portability_test';

		public $_id;
		public $name;
		public $age;
		public $active;

		public static function finder($className = __CLASS__)
		{
			return parent::finder($className);
		}
	}
}

class ActiveRecordMongoTest extends PHPUnit\Framework\TestCase
{
	use MongoActiveRecordCapabilityTrait;

	private ?TMongoConnection $_conn = null;

	protected function setUp(): void
	{
		$this->requireActiveRecord();

		if (!extension_loaded('mongodb')) {
			$this->markTestSkipped('The mongodb extension is not available.');
		}

		$uri = getenv('MONGODB_URI') ?: 'mongodb://localhost:27017';
		$db = getenv('MONGODB_DATABASE') ?: 'prado_unitest';

		$this->_conn = new TMongoConnection($uri, '', '', $db);
		try {
			$this->_conn->setActive(true);
			$manager = $this->_conn->getManager();
			$manager->executeCommand($db, new \MongoDB\Driver\Command(['ping' => 1]));

			// Start from a clean, *schemaless* collection (no JSON Schema
			// validator).  This proves AR works against a plain document store
			// with no predefined schema: when the metadata declares no writable
			// columns, the gateway derives the fields from the record's public
			// properties.  Mongo auto-creates the collection on first insert.
			try {
				$manager->executeCommand($db, new \MongoDB\Driver\Command(['drop' => MongoArUser::TABLE]));
			} catch (\Exception $e) {
				// collection may not exist yet — ignore
			}
		} catch (\Exception $e) {
			$this->markTestSkipped('MongoDB is not available: ' . $e->getMessage());
		}

		// Point the ActiveRecord stack at the Mongo connection — the single
		// line a real application changes to "drop in" Mongo.
		TActiveRecordManager::getInstance()->setDbConnection($this->_conn);
	}

	protected function tearDown(): void
	{
		if ($this->_conn !== null && $this->_conn->getActive()) {
			try {
				$db = $this->_conn->getDatabaseName();
				$this->_conn->getManager()->executeCommand(
					$db,
					new \MongoDB\Driver\Command(['drop' => MongoArUser::TABLE])
				);
			} catch (\Exception $e) {
				// best-effort cleanup
			}
		}
	}

	private function makeUser(string $name, int $age, bool $active = true): MongoArUser
	{
		$u = new MongoArUser();
		$u->name = $name;
		$u->age = $age;
		$u->active = $active;
		return $u;
	}


	// -----------------------------------------------------------------------
	// save / insert
	// -----------------------------------------------------------------------

	public function test_save_inserts_and_assigns_id(): void
	{
		$u = $this->makeUser('Alice', 30);
		$ok = $u->save();

		$this->assertTrue($ok, 'save() should return true on insert');
		$this->assertNotNull($u->_id, 'the generated _id should be assigned back to the record');
	}

	// -----------------------------------------------------------------------
	// findByPk
	// -----------------------------------------------------------------------

	public function test_findByPk_roundtrips_a_saved_record(): void
	{
		$this->requireSchemalessRecordFields();
		$u = $this->makeUser('Bob', 42);
		$u->save();

		$found = MongoArUser::finder()->findByPk($u->_id);

		$this->assertInstanceOf(MongoArUser::class, $found);
		$this->assertSame('Bob', $found->name);
		$this->assertSame(42, (int) $found->age);
	}

	public function test_findByPk_returns_null_for_missing(): void
	{
		$found = MongoArUser::finder()->findByPk(new \MongoDB\BSON\ObjectId());
		$this->assertNull($found);
	}

	// -----------------------------------------------------------------------
	// findAll / count via TMongoQueryCriteria
	// -----------------------------------------------------------------------

	public function test_findAll_with_mongo_criteria(): void
	{
		$this->requireSchemalessRecordFields();
		$a = $this->makeUser('Carol', 25, true);
		$a->save();
		$b = $this->makeUser('Dave', 25, false);
		$b->save();

		$criteria = new \Prado\Data\DataGateway\TMongoQueryCriteria(['age' => 25, 'active' => true]);
		$results = MongoArUser::finder()->findAll($criteria);

		$this->assertIsArray($results);
		$names = array_map(fn($r) => $r->name, $results);
		$this->assertContains('Carol', $names);
		$this->assertNotContains('Dave', $names);
	}

	public function test_count_with_mongo_criteria(): void
	{
		$this->requireSchemalessRecordFields();
		$a = $this->makeUser('Eve', 99);
		$a->save();

		$criteria = new \Prado\Data\DataGateway\TMongoQueryCriteria(['age' => 99]);
		$count = MongoArUser::finder()->count($criteria);

		$this->assertGreaterThanOrEqual(1, $count);
	}

	// -----------------------------------------------------------------------
	// update
	// -----------------------------------------------------------------------

	public function test_save_updates_existing_record(): void
	{
		$this->requireSchemalessRecordFields();
		$u = $this->makeUser('Frank', 50);
		$u->save();

		$u->name = 'Franklin';
		$u->age = 51;
		$u->save();

		$reloaded = MongoArUser::finder()->findByPk($u->_id);
		$this->assertSame('Franklin', $reloaded->name);
		$this->assertSame(51, (int) $reloaded->age);
	}

	// -----------------------------------------------------------------------
	// delete
	// -----------------------------------------------------------------------

	public function test_delete_removes_record(): void
	{
		$u = $this->makeUser('Grace', 60);
		$u->save();
		$id = $u->_id;

		$u->delete();

		$this->assertNull(MongoArUser::finder()->findByPk($id));
	}

	// -----------------------------------------------------------------------
	// Criteria pass-through.  Documents are seeded directly on the connection so
	// these run on every ActiveRecord version, including those whose record
	// gateway has no schemaless insert path.
	// -----------------------------------------------------------------------

	private function seedDirect(): void
	{
		$this->_conn->createCommand(MongoArUser::TABLE)->insertMany([
			['name' => 'Hal', 'age' => 7, 'active' => true],
			['name' => 'Ida', 'age' => 7, 'active' => false],
			['name' => 'Jon', 'age' => 8, 'active' => true],
		]);
	}

	public function test_find_and_findAll_honor_mongo_criteria(): void
	{
		$this->seedDirect();

		$found = MongoArUser::finder()->find(new \Prado\Data\DataGateway\TMongoQueryCriteria(['name' => 'Jon']));
		$this->assertInstanceOf(MongoArUser::class, $found);
		$this->assertSame('Jon', $found->name);

		$names = array_map(
			fn ($r) => $r->name,
			MongoArUser::finder()->findAll(new \Prado\Data\DataGateway\TMongoQueryCriteria(['age' => 7]))
		);
		sort($names);
		$this->assertSame(['Hal', 'Ida'], $names);
		$this->assertSame(2, MongoArUser::finder()->count(new \Prado\Data\DataGateway\TMongoQueryCriteria(['age' => 7])));
	}

	public function test_deleteAll_with_mongo_criteria_deletes_only_matches(): void
	{
		$this->seedDirect();

		MongoArUser::finder()->deleteAll(new \Prado\Data\DataGateway\TMongoQueryCriteria(['active' => false]));

		$names = array_map(fn ($r) => $r->name, MongoArUser::finder()->findAll());
		sort($names);
		$this->assertSame(['Hal', 'Jon'], $names);
	}

	public function test_sql_condition_is_rejected_rather_than_matching_all(): void
	{
		$this->seedDirect();

		try {
			MongoArUser::finder()->deleteAll('active = ?', false);
			$this->fail('A SQL condition must not be executed on MongoDB.');
		} catch (\Prado\Exceptions\TDbException $e) {
			$this->assertStringContainsString('SQL', $e->getMessage());
		}
		$this->assertSame(3, MongoArUser::finder()->count());
	}
}

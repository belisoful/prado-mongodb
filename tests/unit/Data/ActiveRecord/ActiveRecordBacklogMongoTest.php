<?php

/**
 * ActiveRecordBacklogMongoTest — completes the ActiveRecord-on-Mongo
 * integration backlog beyond basic CRUD and single-level relations.
 *
 * Each test drives the real framework machinery against a running mongod and
 * proves a feature that was previously "should-work-but-untested":
 *
 *   - test_sibling_eager_load            : two relations on one record in one with*() chain
 *   - test_deep_relation_manual_traversal: composing relations across levels (manual)
 *   - test_eager_load_with_criteria      : with*($criteria) filtering the related set
 *   - test_eager_load_with_portable_ordering : with*($criteria) ordering the related set
 *   - test_lazy_load_via_getter          : the canonical private-prop + getter lazy path
 *   - test_column_mapping_roundtrip      : COLUMN_MAPPING behavior on a schemaless store
 *   - test_find_all_by_index_direct      : the public batch finder used outside relations
 *
 * Requires a live MongoDB instance; skipped when unavailable.
 */

use Prado\Data\ActiveRecord\TActiveRecord;
use Prado\Data\ActiveRecord\TActiveRecordCriteria;
use Prado\Data\ActiveRecord\TActiveRecordManager;
use Prado\Data\DataGateway\TMongoQueryCriteria;
use Prado\Data\TMongoConnection;
if (class_exists(TActiveRecord::class)) {

	// ---------------------------------------------------------------------------
	// Models — Author 1─* Post 1─* Comment, plus Author 1─1 Profile
	// ---------------------------------------------------------------------------

	class BklAuthor extends TActiveRecord
	{
		public const TABLE = 'bkl_author';
		public $_id;
		public $name;
		public $Posts = [];      // HAS_MANY
		public $Profile;  // HAS_ONE

		public static $RELATIONS = [
			'Posts' => [self::HAS_MANY, 'BklPost', 'author_id'],
			'Profile' => [self::HAS_ONE, 'BklProfile', 'author_id'],
		];

		public static function finder($className = __CLASS__)
		{
			return parent::finder($className);
		}
	}

	class BklProfile extends TActiveRecord
	{
		public const TABLE = 'bkl_profile';
		public $_id;
		public $author_id;
		public $bio;

		public static function finder($className = __CLASS__)
		{
			return parent::finder($className);
		}
	}

	class BklPost extends TActiveRecord
	{
		public const TABLE = 'bkl_post';
		public $_id;
		public $title;
		public $author_id;
		public $Comments = [];  // HAS_MANY

		public static $RELATIONS = [
			'Comments' => [self::HAS_MANY, 'BklComment', 'post_id'],
		];

		public static function finder($className = __CLASS__)
		{
			return parent::finder($className);
		}
	}

	class BklComment extends TActiveRecord
	{
		public const TABLE = 'bkl_comment';
		public $_id;
		public $post_id;
		public $body;

		public static function finder($className = __CLASS__)
		{
			return parent::finder($className);
		}
	}

	// Lazy-load model: relation is exposed through a private field + getter so it
	// does NOT shadow TComponent::__get (a public $Posts would).
	class BklLazyAuthor extends TActiveRecord
	{
		public const TABLE = 'bkl_author';
		public $_id;
		public $name;
		private $_posts;

		public static $RELATIONS = [
			'Posts' => [self::HAS_MANY, 'BklPost', 'author_id'],
		];

		public function getPosts()
		{
			if ($this->_posts === null) {
				$this->fetchResultsFor('Posts');
			}
			return $this->_posts;
		}

		public function setPosts($value)
		{
			$this->_posts = $value;
		}

		public static function finder($className = __CLASS__)
		{
			return parent::finder($className);
		}
	}

	// COLUMN_MAPPING model: physical field <-> logical property.
	class BklMapped extends TActiveRecord
	{
		public const TABLE = 'bkl_mapped';
		public $_id;
		public $fullName;
		public $yearBorn;

		public static $COLUMN_MAPPING = [
			'full_name' => 'fullName',
			'year_born' => 'yearBorn',
		];

		public static function finder($className = __CLASS__)
		{
			return parent::finder($className);
		}
	}
}

class ActiveRecordBacklogMongoTest extends PHPUnit\Framework\TestCase
{
	use MongoActiveRecordCapabilityTrait;

	private const COLLECTIONS = ['bkl_author', 'bkl_post', 'bkl_comment', 'bkl_profile', 'bkl_mapped'];

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
			foreach (self::COLLECTIONS as $coll) {
				try {
					$manager->executeCommand($db, new \MongoDB\Driver\Command(['drop' => $coll]));
				} catch (\Exception $e) {
					// ignore
				}
			}
		} catch (\Exception $e) {
			$this->markTestSkipped('MongoDB is not available: ' . $e->getMessage());
		}

		TActiveRecordManager::getInstance()->setDbConnection($this->_conn);
	}

	protected function tearDown(): void
	{
		if ($this->_conn !== null && $this->_conn->getActive()) {
			$db = $this->_conn->getDatabaseName();
			foreach (self::COLLECTIONS as $coll) {
				try {
					$this->_conn->getManager()->executeCommand($db, new \MongoDB\Driver\Command(['drop' => $coll]));
				} catch (\Exception $e) {
					// ignore
				}
			}
		}
	}

	// -----------------------------------------------------------------------

	private function seedAuthorWithPosts(string $author, array $posts): BklAuthor
	{
		$a = new BklAuthor();
		$a->name = $author;
		$a->save();
		foreach ($posts as $title) {
			$p = new BklPost();
			$p->title = $title;
			$p->author_id = $a->_id;
			$p->save();
		}
		return $a;
	}

	// -----------------------------------------------------------------------
	// Sibling eager load: two relations on one record in a single finder chain.
	// (Prado's with*() chaining loads sibling relations on the SAME record;
	// deep multi-level eager loading is not a framework feature for any driver.)
	// -----------------------------------------------------------------------

	public function test_sibling_eager_load(): void
	{
		$this->requireSchemalessRelations();
		$author = $this->seedAuthorWithPosts('Ada', ['On Algorithms', 'On Engines']);
		$profile = new BklProfile();
		$profile->author_id = $author->_id;
		$profile->bio = 'First programmer';
		$profile->save();

		$found = BklAuthor::finder()->withPosts()->withProfile()->findByPk($author->_id);

		$this->assertCount(2, $found->Posts);
		$this->assertInstanceOf(BklProfile::class, $found->Profile);
		$this->assertSame('First programmer', $found->Profile->bio);
	}

	// -----------------------------------------------------------------------
	// Deep traversal is performed manually (load posts, then comments per post)
	// — proving the relation machinery composes across levels on Mongo.
	// -----------------------------------------------------------------------

	public function test_deep_relation_manual_traversal(): void
	{
		$this->requireSchemalessRelations();
		$author = $this->seedAuthorWithPosts('Babbage', ['Difference Engine']);
		$post = BklPost::finder()->find(new TMongoQueryCriteria(['title' => 'Difference Engine']));
		foreach (['Great read', 'Insightful', 'Thanks'] as $body) {
			$c = new BklComment();
			$c->post_id = $post->_id;
			$c->body = $body;
			$c->save();
		}

		$found = BklAuthor::finder()->withPosts()->findByPk($author->_id);
		$this->assertCount(1, $found->Posts);

		$loadedPost = BklPost::finder()->withComments()->findByPk($found->Posts[0]->_id);
		$this->assertCount(3, $loadedPost->Comments);
		$bodies = array_map(fn ($c) => $c->body, $loadedPost->Comments);
		sort($bodies);
		$this->assertSame(['Great read', 'Insightful', 'Thanks'], $bodies);
	}

	// -----------------------------------------------------------------------
	// Eager load with a criteria argument restricting the related set
	// -----------------------------------------------------------------------

	public function test_eager_load_with_criteria(): void
	{
		$this->requireSchemalessRelations();
		$author = $this->seedAuthorWithPosts('Babbage', ['Keep', 'Drop', 'Keep Two']);

		// A Mongo-native filter criteria: only posts whose title starts with "Keep".
		$criteria = new TMongoQueryCriteria(['title' => ['$regex' => '^Keep']]);
		$found = BklAuthor::finder()->withPosts($criteria)->findByPk($author->_id);

		$titles = array_map(fn ($p) => $p->title, $found->Posts);
		sort($titles);
		$this->assertSame(['Keep', 'Keep Two'], $titles);
	}

	public function test_eager_load_with_portable_ordering(): void
	{
		$this->requireSchemalessRelations();
		$author = $this->seedAuthorWithPosts('Curie', ['Gamma', 'Alpha', 'Beta']);

		$criteria = new TActiveRecordCriteria();
		$criteria->OrdersBy['title'] = 'asc';
		$found = BklAuthor::finder()->withPosts($criteria)->findByPk($author->_id);

		$titles = array_map(fn ($p) => $p->title, $found->Posts);
		$this->assertSame(['Alpha', 'Beta', 'Gamma'], $titles);
	}

	// -----------------------------------------------------------------------
	// Lazy load via the canonical private-prop + getter pattern
	// -----------------------------------------------------------------------

	public function test_lazy_load_via_getter(): void
	{
		$this->requireSchemalessRelations();
		$author = $this->seedAuthorWithPosts('Dijkstra', ['Go To Considered Harmful', 'EWD']);

		// No with*() — the relation is fetched on first property access.
		$found = BklLazyAuthor::finder()->findByPk($author->_id);
		$this->assertCount(2, $found->Posts);   // triggers lazy fetchResultsFor('Posts')

		$titles = array_map(fn ($p) => $p->title, $found->Posts);
		sort($titles);
		$this->assertSame(['EWD', 'Go To Considered Harmful'], $titles);
	}

	// -----------------------------------------------------------------------
	// COLUMN_MAPPING round-trip on a schemaless store
	// -----------------------------------------------------------------------

	public function test_column_mapping_roundtrip(): void
	{
		$this->requireSchemalessRecordFields();
		$m = new BklMapped();
		$m->fullName = 'Grace Hopper';
		$m->yearBorn = 1906;
		$m->save();

		$found = BklMapped::finder()->findByPk($m->_id);
		$this->assertSame('Grace Hopper', $found->fullName);
		$this->assertSame(1906, $found->yearBorn);

		// Document the schemaless storage shape: with no column metadata the
		// gateway derives field names from the record's properties (logical
		// names), so COLUMN_MAPPING's physical names are not materialized.
		$raw = $this->_conn->createCommand('bkl_mapped')->findOne(['fullName' => 'Grace Hopper']);
		$this->assertNotNull($raw);
		$this->assertArrayHasKey('fullName', $raw);
		$this->assertArrayNotHasKey('full_name', $raw);
	}

	// -----------------------------------------------------------------------
	// Public findAllByIndex (the batch finder relations use internally)
	// -----------------------------------------------------------------------

	public function test_find_all_by_index_direct(): void
	{
		$this->requireSchemalessRecordFields();
		$this->seedAuthorWithPosts('Euler', []);
		$this->seedAuthorWithPosts('Gauss', []);
		$this->seedAuthorWithPosts('Riemann', []);

		$found = BklAuthor::finder()->findAllByIndex(
			new TActiveRecordCriteria(),
			['name'],
			['Euler', 'Riemann']
		);

		$names = array_map(fn ($a) => $a->name, $found);
		sort($names);
		$this->assertSame(['Euler', 'Riemann'], $names);
	}
}

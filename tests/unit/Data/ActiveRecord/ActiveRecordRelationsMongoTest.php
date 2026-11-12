<?php

/**
 * ActiveRecordRelationsMongoTest — integration test for ActiveRecord
 * relationship loading against a live MongoDB connection.
 *
 * Exercises HasMany, BelongsTo, HasOne, and Many-to-Many (via association
 * collection) through the real framework relation machinery (P6/P7) against
 * a running mongod, proving relations are a drop-in alongside SQL.
 *
 * Relations declare their foreign-key field explicitly (the 3rd element of a
 * $RELATIONS entry); the framework's findForeignKeys() falls back to that
 * declaration when the store provides no FK-constraint metadata.
 *
 * Requires a live MongoDB instance; skipped when unavailable.
 */

use Prado\Data\ActiveRecord\TActiveRecord;
use Prado\Data\ActiveRecord\TActiveRecordManager;
use Prado\Data\TMongoConnection;

if (class_exists(TActiveRecord::class)) {
	class RelAuthor extends TActiveRecord
	{
		public const TABLE = 'rel_author';
		public $_id;
		public $name;
		public $Posts = [];      // HAS_MANY
		public $Profile = null;  // HAS_ONE

		public static $RELATIONS = [
			'Posts'   => [self::HAS_MANY, RelPost::class, 'author_id'],
			'Profile' => [self::HAS_ONE, 'RelProfile', 'author_id'],
		];

		public static function finder($className = __CLASS__)
		{
			return parent::finder($className);
		}
	}

	class RelPost extends TActiveRecord
	{
		public const TABLE = 'rel_post';
		public $_id;
		public $title;
		public $author_id;
		public $Author = null;   // BELONGS_TO

		public static $RELATIONS = [
			'Author' => [self::BELONGS_TO, 'RelAuthor', 'author_id'],
		];

		public static function finder($className = __CLASS__)
		{
			return parent::finder($className);
		}
	}

	class RelProfile extends TActiveRecord
	{
		public const TABLE = 'rel_profile';
		public $_id;
		public $author_id;
		public $bio;

		public static function finder($className = __CLASS__)
		{
			return parent::finder($className);
		}
	}

	class RelArticle extends TActiveRecord
	{
		public const TABLE = 'rel_article';
		public $_id;
		public $title;
		public $Categories = [];   // MANY_TO_MANY via rel_article_category

		public static $RELATIONS = [
			// pivot collection 'rel_article_category' with fields article_id, category_id.
			// Schemaless stores declare both pivot FK columns: (sourceFk, foreignFk).
			'Categories' => [self::MANY_TO_MANY, 'RelCategory', 'rel_article_category.(article_id, category_id)'],
		];

		public static function finder($className = __CLASS__)
		{
			return parent::finder($className);
		}
	}

	class RelCategory extends TActiveRecord
	{
		public const TABLE = 'rel_category';
		public $_id;
		public $name;

		public static function finder($className = __CLASS__)
		{
			return parent::finder($className);
		}
	}
}

class ActiveRecordRelationsMongoTest extends PHPUnit\Framework\TestCase
{
	use MongoActiveRecordCapabilityTrait;

	private const COLLECTIONS = [
		'rel_author', 'rel_post', 'rel_profile',
		'rel_article', 'rel_category', 'rel_article_category',
	];

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

	private function makeAuthor(string $name): RelAuthor
	{
		$a = new RelAuthor();
		$a->name = $name;
		$a->save();
		return $a;
	}

	private function makePost(string $title, $authorId): RelPost
	{
		$p = new RelPost();
		$p->title = $title;
		$p->author_id = $authorId;
		$p->save();
		return $p;
	}

	// -----------------------------------------------------------------------
	// HAS_MANY
	// -----------------------------------------------------------------------

	public function test_has_many_eager_load(): void
	{
		$this->requireSchemalessRelations();
		$author = $this->makeAuthor('Ada');
		$this->makePost('Post A', $author->_id);
		$this->makePost('Post B', $author->_id);
		$other = $this->makeAuthor('Babbage');
		$this->makePost('Post C', $other->_id);

		$found = RelAuthor::finder()->withPosts()->findByPk($author->_id);

		$this->assertCount(2, $found->Posts);
		$titles = array_map(fn($p) => $p->title, $found->Posts);
		sort($titles);
		$this->assertSame(['Post A', 'Post B'], $titles);
	}

	// -----------------------------------------------------------------------
	// BELONGS_TO
	// -----------------------------------------------------------------------

	public function test_belongs_to_eager_load(): void
	{
		$this->requireSchemalessRelations();
		$author = $this->makeAuthor('Curie');
		$post = $this->makePost('Radioactivity', $author->_id);

		$found = RelPost::finder()->withAuthor()->findByPk($post->_id);

		$this->assertInstanceOf(RelAuthor::class, $found->Author);
		$this->assertSame('Curie', $found->Author->name);
	}

	// -----------------------------------------------------------------------
	// HAS_ONE
	// -----------------------------------------------------------------------

	public function test_has_one_eager_load(): void
	{
		$this->requireSchemalessRelations();
		$author = $this->makeAuthor('Dijkstra');
		$profile = new RelProfile();
		$profile->author_id = $author->_id;
		$profile->bio = 'Structured programming';
		$profile->save();

		$found = RelAuthor::finder()->withProfile()->findByPk($author->_id);

		$this->assertInstanceOf(RelProfile::class, $found->Profile);
		$this->assertSame('Structured programming', $found->Profile->bio);
	}

	// -----------------------------------------------------------------------
	// MANY_TO_MANY (via association/pivot collection)
	// -----------------------------------------------------------------------

	public function test_many_to_many_eager_load(): void
	{
		$this->requireSchemalessManyToMany();
		// Articles and Categories, linked through the rel_article_category pivot.
		$article = new RelArticle();
		$article->title = 'Mongo & AR';
		$article->save();

		$tech = new RelCategory();
		$tech->name = 'Tech';
		$tech->save();
		$db = new RelCategory();
		$db->name = 'Databases';
		$db->save();
		$unrelated = new RelCategory();
		$unrelated->name = 'Cooking';
		$unrelated->save();

		// Pivot rows: article ↔ {tech, db}.  Written through the portable gateway.
		$pivot = $this->_conn->createCommand('rel_article_category');
		$pivot->insertOne(['article_id' => $article->_id, 'category_id' => $tech->_id]);
		$pivot->insertOne(['article_id' => $article->_id, 'category_id' => $db->_id]);

		$found = RelArticle::finder()->withCategories()->findByPk($article->_id);

		$this->assertCount(2, $found->Categories);
		$names = array_map(fn($c) => $c->name, $found->Categories);
		sort($names);
		$this->assertSame(['Databases', 'Tech'], $names);
	}

}

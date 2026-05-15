<?php

use Prado\Data\Common\Mongo\TMongoCollectionInfo;
use Prado\Data\Common\Mongo\TMongoCommandBuilder;
use Prado\Data\Common\Mongo\TMongoFieldInfo;
use Prado\Data\TMongoCommand;
use Prado\Data\TMongoConnection;

class CommandBuilderMongoTest extends PHPUnit\Framework\TestCase
{
	protected function setUp(): void
	{
		if (!extension_loaded('mongodb')) {
			$this->markTestSkipped('The mongodb extension is not available.');
		}
	}

	protected function makeBuilder(array $fields = []): TMongoCommandBuilder
	{
		// An inactive connection is sufficient — builder methods that don't create
		// commands work entirely on arrays and never touch the driver.
		$conn = new TMongoConnection('mongodb://localhost:27017', '', '', 'prado_unitest');
		$info = new TMongoCollectionInfo('table1', $fields);
		return new TMongoCommandBuilder($conn, $info);
	}

	protected function makeBuilderWithFields(): TMongoCommandBuilder
	{
		$fields = [
			'_id'    => new TMongoFieldInfo(['FieldName' => '_id',    'BsonType' => 'objectId', 'Required' => false, 'Description' => []]),
			'name'   => new TMongoFieldInfo(['FieldName' => 'name',   'BsonType' => 'string',   'Required' => true,  'Description' => []]),
			'age'    => new TMongoFieldInfo(['FieldName' => 'age',    'BsonType' => 'int',      'Required' => false, 'Description' => []]),
			'active' => new TMongoFieldInfo(['FieldName' => 'active', 'BsonType' => 'bool',     'Required' => false, 'Description' => []]),
		];
		return $this->makeBuilder($fields);
	}

	// -----------------------------------------------------------------------
	// applyLimitSkip
	// -----------------------------------------------------------------------

	public function test_no_limit_no_skip()
	{
		$builder = $this->makeBuilder();
		$options = [];
		$builder->applyLimitSkip($options, 0, 0);
		$this->assertArrayNotHasKey('limit', $options);
		$this->assertArrayNotHasKey('skip', $options);
	}

	public function test_negative_limit_no_skip()
	{
		$builder = $this->makeBuilder();
		$options = [];
		$builder->applyLimitSkip($options, -1, -1);
		$this->assertArrayNotHasKey('limit', $options);
		$this->assertArrayNotHasKey('skip', $options);
	}

	public function test_limit_only()
	{
		$builder = $this->makeBuilder();
		$options = [];
		$builder->applyLimitSkip($options, 5, 0);
		$this->assertEquals(5, $options['limit']);
		$this->assertArrayNotHasKey('skip', $options);
	}

	public function test_skip_only()
	{
		$builder = $this->makeBuilder();
		$options = [];
		$builder->applyLimitSkip($options, 0, 10);
		$this->assertArrayNotHasKey('limit', $options);
		$this->assertEquals(10, $options['skip']);
	}

	public function test_limit_and_skip()
	{
		$builder = $this->makeBuilder();
		$options = [];
		$builder->applyLimitSkip($options, 5, 10);
		$this->assertEquals(5, $options['limit']);
		$this->assertEquals(10, $options['skip']);
	}

	public function test_limit_and_skip_preserves_existing_options()
	{
		$builder = $this->makeBuilder();
		$options = ['sort' => ['name' => 1]];
		$builder->applyLimitSkip($options, 3, 2);
		$this->assertEquals(['name' => 1], $options['sort']);
		$this->assertEquals(3, $options['limit']);
		$this->assertEquals(2, $options['skip']);
	}

	// -----------------------------------------------------------------------
	// applySort
	// -----------------------------------------------------------------------

	public function test_apply_sort_empty_does_nothing()
	{
		$builder = $this->makeBuilder();
		$options = [];
		$builder->applySort($options, []);
		$this->assertArrayNotHasKey('sort', $options);
	}

	public function test_apply_sort_single_field_asc()
	{
		$builder = $this->makeBuilder();
		$options = [];
		$builder->applySort($options, ['name' => 1]);
		$this->assertEquals(['name' => 1], $options['sort']);
	}

	public function test_apply_sort_multiple_fields()
	{
		$builder = $this->makeBuilder();
		$options = [];
		$builder->applySort($options, ['age' => -1, 'name' => 1]);
		$this->assertEquals(['age' => -1, 'name' => 1], $options['sort']);
	}

	// -----------------------------------------------------------------------
	// applyProjection
	// -----------------------------------------------------------------------

	public function test_apply_projection_star_skips()
	{
		$builder = $this->makeBuilderWithFields();
		$options = [];
		$builder->applyProjection($options, '*');
		$this->assertArrayNotHasKey('projection', $options);
	}

	public function test_apply_projection_empty_array_skips()
	{
		$builder = $this->makeBuilderWithFields();
		$options = [];
		$builder->applyProjection($options, []);
		$this->assertArrayNotHasKey('projection', $options);
	}

	public function test_apply_projection_null_uses_schema_fields()
	{
		$builder = $this->makeBuilderWithFields();
		$options = [];
		$builder->applyProjection($options, null);
		$this->assertArrayHasKey('projection', $options);
		$this->assertArrayHasKey('_id', $options['projection']);
		$this->assertArrayHasKey('name', $options['projection']);
		$this->assertArrayHasKey('age', $options['projection']);
		$this->assertArrayHasKey('active', $options['projection']);
		// All values should be 1 (inclusion projection)
		foreach ($options['projection'] as $val) {
			$this->assertEquals(1, $val);
		}
	}

	public function test_apply_projection_null_empty_schema_skips()
	{
		// No known fields — should not set projection key
		$builder = $this->makeBuilder([]);
		$options = [];
		$builder->applyProjection($options, null);
		$this->assertArrayNotHasKey('projection', $options);
	}

	public function test_apply_projection_array_verbatim()
	{
		$builder = $this->makeBuilder();
		$options = [];
		$proj = ['name' => 1, '_id' => 0];
		$builder->applyProjection($options, $proj);
		$this->assertEquals($proj, $options['projection']);
	}

	public function test_apply_projection_comma_string()
	{
		$builder = $this->makeBuilder();
		$options = [];
		$builder->applyProjection($options, 'name, age, active');
		$this->assertArrayHasKey('projection', $options);
		$this->assertArrayHasKey('name', $options['projection']);
		$this->assertArrayHasKey('age', $options['projection']);
		$this->assertArrayHasKey('active', $options['projection']);
		$this->assertArrayNotHasKey('_id', $options['projection']);
	}

	// -----------------------------------------------------------------------
	// getSearchFilter
	// -----------------------------------------------------------------------

	public function test_search_filter_empty_fields_returns_empty()
	{
		$builder = $this->makeBuilder();
		$filter = $builder->getSearchFilter([], 'hello');
		$this->assertEquals([], $filter);
	}

	public function test_search_filter_empty_keywords_returns_empty()
	{
		$builder = $this->makeBuilder();
		$filter = $builder->getSearchFilter(['name', 'bio'], '   ');
		$this->assertEquals([], $filter);
	}

	public function test_search_filter_single_field_single_keyword()
	{
		$builder = $this->makeBuilder();
		$filter = $builder->getSearchFilter(['name'], 'alice');
		$this->assertArrayHasKey('$or', $filter);
		$this->assertCount(1, $filter['$or']);
		$this->assertArrayHasKey('name', $filter['$or'][0]);
		$this->assertEquals('alice', $filter['$or'][0]['name']['$regex']);
		$this->assertEquals('i', $filter['$or'][0]['name']['$options']);
	}

	public function test_search_filter_multiple_fields_single_keyword()
	{
		$builder = $this->makeBuilder();
		$filter = $builder->getSearchFilter(['name', 'bio'], 'alice');
		$this->assertCount(2, $filter['$or']);
	}

	public function test_search_filter_multiple_keywords()
	{
		$builder = $this->makeBuilder();
		$filter = $builder->getSearchFilter(['name'], 'alice bob');
		// 1 field × 2 keywords = 2 $or conditions
		$this->assertCount(2, $filter['$or']);
	}

	public function test_search_filter_special_regex_chars_escaped()
	{
		$builder = $this->makeBuilder();
		$filter = $builder->getSearchFilter(['name'], 'hello.world');
		$regex = $filter['$or'][0]['name']['$regex'];
		// The dot should be escaped in the regex
		$this->assertStringContainsString('\.', $regex);
	}

	// -----------------------------------------------------------------------
	// getSelectFieldList
	// -----------------------------------------------------------------------

	public function test_select_field_list_star_returns_empty()
	{
		$builder = $this->makeBuilderWithFields();
		$this->assertEquals([], $builder->getSelectFieldList('*'));
	}

	public function test_select_field_list_null_returns_schema_fields()
	{
		$builder = $this->makeBuilderWithFields();
		$fields = $builder->getSelectFieldList(null);
		$this->assertContains('_id', $fields);
		$this->assertContains('name', $fields);
		$this->assertContains('age', $fields);
		$this->assertContains('active', $fields);
	}

	public function test_select_field_list_comma_string()
	{
		$builder = $this->makeBuilder();
		$fields = $builder->getSelectFieldList('name, age');
		$this->assertContains('name', $fields);
		$this->assertContains('age', $fields);
	}

	public function test_select_field_list_array_returns_keys()
	{
		$builder = $this->makeBuilder();
		$fields = $builder->getSelectFieldList(['name' => 'Alice', 'age' => 30]);
		$this->assertEquals(['name', 'age'], $fields);
	}

	// -----------------------------------------------------------------------
	// Command factory methods — check operation type without executing
	// -----------------------------------------------------------------------

	public function test_create_find_command()
	{
		$builder = $this->makeBuilder();
		$cmd = $builder->createFindCommand(['active' => true]);
		$this->assertInstanceOf(TMongoCommand::class, $cmd);
		$this->assertEquals(TMongoCommand::OP_FIND, $cmd->getOperation());
		$this->assertEquals(['active' => true], $cmd->getFilter());
	}

	public function test_create_insert_one_command()
	{
		$builder = $this->makeBuilder();
		$doc = ['name' => 'test', 'age' => 25];
		$cmd = $builder->createInsertOneCommand($doc);
		$this->assertEquals(TMongoCommand::OP_INSERT_ONE, $cmd->getOperation());
		$this->assertEquals($doc, $cmd->getDocument());
	}

	public function test_create_insert_many_command()
	{
		$builder = $this->makeBuilder();
		$docs = [['name' => 'a'], ['name' => 'b']];
		$cmd = $builder->createInsertManyCommand($docs);
		$this->assertEquals(TMongoCommand::OP_INSERT_MANY, $cmd->getOperation());
		$this->assertEquals($docs, $cmd->getDocuments());
	}

	public function test_create_update_one_command()
	{
		$builder = $this->makeBuilder();
		$cmd = $builder->createUpdateOneCommand(['name' => 'a'], ['$set' => ['name' => 'b']]);
		$this->assertEquals(TMongoCommand::OP_UPDATE_ONE, $cmd->getOperation());
		$this->assertEquals(['name' => 'a'], $cmd->getFilter());
		$this->assertEquals(['$set' => ['name' => 'b']], $cmd->getUpdate());
	}

	public function test_create_update_many_command()
	{
		$builder = $this->makeBuilder();
		$cmd = $builder->createUpdateManyCommand(['active' => false], ['$set' => ['active' => true]]);
		$this->assertEquals(TMongoCommand::OP_UPDATE_MANY, $cmd->getOperation());
	}

	public function test_create_delete_one_command()
	{
		$builder = $this->makeBuilder();
		$cmd = $builder->createDeleteOneCommand(['name' => 'test']);
		$this->assertEquals(TMongoCommand::OP_DELETE_ONE, $cmd->getOperation());
		$this->assertEquals(['name' => 'test'], $cmd->getFilter());
	}

	public function test_create_delete_many_command()
	{
		$builder = $this->makeBuilder();
		$cmd = $builder->createDeleteManyCommand(['active' => false]);
		$this->assertEquals(TMongoCommand::OP_DELETE_MANY, $cmd->getOperation());
	}

	public function test_create_count_command()
	{
		$builder = $this->makeBuilder();
		$cmd = $builder->createCountCommand(['active' => true]);
		$this->assertEquals(TMongoCommand::OP_COUNT, $cmd->getOperation());
		$this->assertEquals(['active' => true], $cmd->getFilter());
	}

	public function test_create_find_command_with_options()
	{
		// Use the MongoDB-native helper when passing a raw options array.
		$builder = $this->makeBuilder();
		$options = ['sort' => ['name' => 1], 'limit' => 10];
		$cmd = $builder->createFindCommandMongo([], $options);
		$this->assertEquals(TMongoCommand::OP_FIND, $cmd->getOperation());
		$this->assertEquals($options, $cmd->getOptions());
	}

	// -----------------------------------------------------------------------
	// SQL-centric IDataCommandBuilder interface methods
	// -----------------------------------------------------------------------

	public function test_create_find_command_sql_ordering_and_limit()
	{
		$builder = $this->makeBuilder();
		// SQL-centric call: $where array, $parameters empty, ordering, limit, offset.
		$cmd = $builder->createFindCommand(['active' => true], [], ['name' => 'asc', 'age' => 'desc'], 5, 10);
		$this->assertInstanceOf(TMongoCommand::class, $cmd);
		$this->assertEquals(TMongoCommand::OP_FIND, $cmd->getOperation());
		$this->assertEquals(['active' => true], $cmd->getFilter());
		$opts = $cmd->getOptions();
		$this->assertEquals(['name' => 1, 'age' => -1], $opts['sort']);
		$this->assertEquals(5, $opts['limit']);
		$this->assertEquals(10, $opts['skip']);
	}

	public function test_create_find_command_parameters_merged_into_filter()
	{
		$builder = $this->makeBuilder();
		$cmd = $builder->createFindCommand(['status' => 'active'], ['role' => 'admin']);
		$this->assertEquals(['status' => 'active', 'role' => 'admin'], $cmd->getFilter());
	}

	public function test_create_find_command_sentinel_where_means_no_filter()
	{
		$builder = $this->makeBuilder();
		$cmd = $builder->createFindCommand('1=1');
		$this->assertEquals([], $cmd->getFilter());
	}

	public function test_create_count_command_sql_interface()
	{
		$builder = $this->makeBuilder();
		$cmd = $builder->createCountCommand('1=1');
		$this->assertEquals(TMongoCommand::OP_COUNT, $cmd->getOperation());
		$this->assertEquals([], $cmd->getFilter());
	}

	public function test_create_insert_command_maps_to_insert_one()
	{
		$builder = $this->makeBuilder();
		$doc = ['name' => 'test', 'age' => 30];
		$cmd = $builder->createInsertCommand($doc);
		$this->assertEquals(TMongoCommand::OP_INSERT_ONE, $cmd->getOperation());
		$this->assertEquals($doc, $cmd->getDocument());
	}

	public function test_create_insert_or_ignore_command_uses_upsert()
	{
		$builder = $this->makeBuilder();
		$doc = ['_id' => 'abc123', 'name' => 'test'];
		$cmd = $builder->createInsertOrIgnoreCommand($doc);
		$this->assertEquals(TMongoCommand::OP_UPDATE_ONE, $cmd->getOperation());
		$this->assertEquals(['_id' => 'abc123'], $cmd->getFilter());
		$update = $cmd->getUpdate();
		$this->assertArrayHasKey('$setOnInsert', $update);
		$opts = $cmd->getOptions();
		$this->assertTrue($opts['upsert']);
	}

	public function test_create_upsert_command()
	{
		$builder = $this->makeBuilder();
		$doc = ['_id' => 'x1', 'name' => 'Alice', 'age' => 30];
		$cmd = $builder->createUpsertCommand($doc);
		$this->assertEquals(TMongoCommand::OP_UPDATE_ONE, $cmd->getOperation());
		$this->assertEquals(['_id' => 'x1'], $cmd->getFilter());
		$this->assertEquals(['$set' => ['name' => 'Alice', 'age' => 30]], $cmd->getUpdate());
		$this->assertTrue($cmd->getOptions()['upsert']);
	}

	public function test_create_upsert_command_with_conflict_columns()
	{
		$builder = $this->makeBuilder();
		$doc = ['email' => 'a@example.com', 'name' => 'Alice'];
		$cmd = $builder->createUpsertCommand($doc, null, ['email']);
		$this->assertEquals(['email' => 'a@example.com'], $cmd->getFilter());
	}

	public function test_create_update_command_wraps_data_in_set()
	{
		$builder = $this->makeBuilder();
		$cmd = $builder->createUpdateCommand(['name' => 'Bob'], ['status' => 'active']);
		$this->assertEquals(TMongoCommand::OP_UPDATE_MANY, $cmd->getOperation());
		$this->assertEquals(['status' => 'active'], $cmd->getFilter());
		$this->assertEquals(['$set' => ['name' => 'Bob']], $cmd->getUpdate());
	}

	public function test_create_delete_command()
	{
		$builder = $this->makeBuilder();
		$cmd = $builder->createDeleteCommand(['status' => 'inactive']);
		$this->assertEquals(TMongoCommand::OP_DELETE_MANY, $cmd->getOperation());
		$this->assertEquals(['status' => 'inactive'], $cmd->getFilter());
	}

	public function test_apply_limit_offset_is_noop()
	{
		$builder = $this->makeBuilder();
		$sql = 'SELECT * FROM users';
		$result = $builder->applyLimitOffset($sql, 10, 5);
		$this->assertEquals($sql, $result, 'applyLimitOffset is a no-op for MongoDB');
	}

	public function test_apply_ordering_is_noop()
	{
		$builder = $this->makeBuilder();
		$sql = 'SELECT * FROM users';
		$result = $builder->applyOrdering($sql, ['name' => 'asc']);
		$this->assertEquals($sql, $result, 'applyOrdering is a no-op for MongoDB');
	}

	public function test_get_search_expression_returns_json()
	{
		$builder = $this->makeBuilder();
		$expr = $builder->getSearchExpression(['name'], 'alice');
		$this->assertIsString($expr);
		$decoded = json_decode($expr, true);
		$this->assertArrayHasKey('$or', $decoded);
	}

	public function test_get_last_insert_id_starts_null()
	{
		$builder = $this->makeBuilder();
		$this->assertNull($builder->getLastInsertID());
	}

	public function test_apply_criterias_returns_find_command()
	{
		$builder = $this->makeBuilder();
		$cmd = $builder->applyCriterias(['active' => true], [], ['name' => 'asc'], 5, 0);
		$this->assertInstanceOf(TMongoCommand::class, $cmd);
		$this->assertEquals(TMongoCommand::OP_FIND, $cmd->getOperation());
	}
}

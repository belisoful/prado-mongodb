# PRADO MongoDB Extension

A native MongoDB integration for the [PRADO PHP Framework](https://github.com/pradosoft/prado) (version 4.3.3+), implemented as a PRADO 4 extension. Provides a full data-access layer — connection, command, data reader, transaction, metadata, command builder, and collection gateway — all conforming to the PRADO `IData*` interface family.

## Requirements

- PHP 8.1 or higher (the plugin source is PHP 8.1 compatible; PRADO 4.4.0 itself needs PHP 8.2 or higher)
- [ext-mongodb](https://pecl.php.net/package/mongodb) (PECL `mongodb` extension)
- [mongodb/mongodb](https://github.com/mongodb/mongo-php-library) PHP library `^2.1` (installed via Composer)
- PRADO Framework 4.3.3 or 4.4.0: `composer.json` requires `pradosoft/prado` `^4.3.3@dev` at runtime, and the one code base supports both lines
- ActiveRecord features (the scaffold input, ActiveRecord over the MongoDB connection) need ActiveRecord: it is built into PRADO 4.3.3; on PRADO 4.4 it is the separate, optional package [`pradosoft/prado-activerecord`](https://github.com/pradosoft/prado-activerecord) (4.4 only), which this plugin does not require (`composer require pradosoft/prado-activerecord`)

## Installation

```sh
composer require belisoful/prado-mongodb
```

### Installing MongoDB Server

#### macOS (Homebrew)

```sh
brew tap mongodb/brew
brew install mongodb-community
brew services start mongodb-community
# or run manually:
mongod --config /opt/homebrew/etc/mongod.conf
```

Verify the daemon is running:

```sh
ps aux | grep mongod
# or open the shell:
mongosh
```

#### Linux (Ubuntu/Debian)

```sh
curl -fsSL https://www.mongodb.org/static/pgp/server-7.0.asc | sudo gpg -o /usr/share/keyrings/mongodb-server-7.0.gpg --dearmor
echo "deb [ arch=amd64,arm64 signed-by=/usr/share/keyrings/mongodb-server-7.0.gpg ] https://repo.mongodb.org/apt/ubuntu jammy/mongodb-org/7.0 multiverse" | sudo tee /etc/apt/sources.list.d/mongodb-org-7.0.list
sudo apt-get update && sudo apt-get install -y mongodb-org
sudo systemctl start mongod
```

### Installing the PHP MongoDB Extension

```sh
pecl install mongodb
```

Add `extension=mongodb.so` to your `php.ini`. Homebrew users may find pre-built formulae via the `shivammathur/extensions` tap.

---

## Architecture

This extension mirrors the structure of the PRADO core data-access layer, implementing the same `IData*` interfaces satisfied by `TDbConnection` and its companions for PDO. All classes live under the `Prado\Data` namespace and are autoloaded via PSR-4 from `src/`.

```
src/
├── Caching/
│   └── TMongoCache.php               # TCache backed by a MongoDB collection
├── Data/
│   ├── TMongoConnection.php      # Wraps MongoDB\Driver\Manager
│   ├── TMongoCommand.php         # Builder + direct-API facade over MongoDB operations
│   ├── TMongoDataReader.php      # Eagerly-buffered cursor reader implementing IDataReader
│   ├── TMongoTransaction.php     # TDataTransaction subclass wrapping a client session + transaction
│   ├── TMongoSourceConfig.php    # PRADO bootstrap / TDataSourceConfig subclass
│   ├── ActiveRecord/Scaffold/InputBuilder/
│   │   └── TMongoScaffoldInput.php   # BSON type → scaffold input controls
│   ├── DataGateway/
│   │   ├── TMongoCollectionGateway.php  # High-level CRUD + magic finders
│   │   └── TMongoQueryCriteria.php      # IDataCriteria carrying a MongoDB filter document
│   └── Common/Mongo/
│       ├── TMongoMetaData.php        # Inspects JSON Schema validators; samples docs
│       ├── TMongoCollectionInfo.php  # IDataTableInfo for a MongoDB collection
│       ├── TMongoFieldInfo.php       # Per-field BSON→PHP type information
│       └── TMongoCommandBuilder.php  # IDataCommandBuilder for MongoDB
└── errorMessages.txt             # Error-key → human-readable message map
```

The `IData*` interfaces that these classes implement (`IDataConnection`, `IDataCommand`, `IDataReader`, `IDataTransaction`, `IDataMetaData`, `IDataCommandBuilder`, `IDataTableInfo`) are part of the PRADO framework.

---

## Quick Start

### Opening a Connection

```php
use Prado\Data\TMongoConnection;

$conn = new TMongoConnection(
    connectionString: 'mongodb://localhost:27017',
    databaseName: 'myapp'
);
$conn->setActive(true);
```

With authentication:

```php
$conn = new TMongoConnection(
    connectionString: 'mongodb://localhost:27017',
    username: 'myuser',
    password: 'mypassword',
    databaseName: 'myapp'
);
$conn->setActive(true);
```

### Running a Find Query

```php
$cmd = $conn->createCommand('users');
$cmd->setFilter(['active' => true])
    ->setSort(['created_at' => -1])
    ->setLimit(20)
    ->setSkip(40);

$reader = $cmd->query();
foreach ($reader as $doc) {
    echo $doc['name'] . PHP_EOL;
}
```

### Direct-API Shortcuts

`TMongoCommand` exposes a convenience API for common single-operation use:

```php
$cmd = $conn->createCommand('users');

// Find one document
$user = $cmd->findOne(['email' => 'alice@example.com']);

// Find many documents
$active = $cmd->findMany(['active' => true], options: ['sort' => ['name' => 1]]);

// Insert documents
$result = $cmd->insertOne(['name' => 'Bob', 'email' => 'bob@example.com']);
$cmd->insertMany([
    ['name' => 'Charlie', 'role' => 'admin'],
    ['name' => 'Dana',    'role' => 'user'],
]);

// Update documents
$cmd->updateOne(['email' => 'bob@example.com'], ['$set' => ['verified' => true]]);
$cmd->updateMany(['active' => false], ['$set' => ['archived' => true]]);

// Delete documents
$cmd->deleteOne(['_id' => $objectId]);
$cmd->deleteMany(['archived' => true]);

// Count documents
$total = $cmd->count(['active' => true]);

// Distinct values
$roles = $cmd->distinct('role', ['active' => true]);

// Aggregation pipeline
$stats = $cmd->aggregate([
    ['$match'  => ['active' => true]],
    ['$group'  => ['_id' => '$role', 'count' => ['$sum' => 1]]],
    ['$sort'   => ['count' => -1]],
]);
```

### Named Operations via Builder Pattern

The same command object supports a chainable builder API for the `query()` / `execute()` path:

```php
$cmd = $conn->createCommand('orders')
    ->setOperation(TMongoCommand::OP_FIND)
    ->setFilter(['status' => 'pending'])
    ->setProjection(['_id' => 1, 'total' => 1])
    ->setSort(['created_at' => 1])
    ->setLimit(50);

$reader = $cmd->query();
```

Available operations: `OP_FIND`, `OP_INSERT_ONE`, `OP_INSERT_MANY`, `OP_UPDATE_ONE`, `OP_UPDATE_MANY`, `OP_DELETE_ONE`, `OP_DELETE_MANY`, `OP_AGGREGATE`, `OP_COUNT`, `OP_DISTINCT`.

### Transactions

Multi-document transactions require a replica set or sharded cluster (not supported on standalone `mongod`).

```php
$tx = $conn->beginTransaction();
try {
    $conn->createCommand('orders')->insertOne(['item' => 'Widget', 'qty' => 5]);
    $conn->createCommand('inventory')->updateOne(
        ['item' => 'Widget'],
        ['$inc' => ['qty' => -5]]
    );
    $tx->commit();
} catch (\Throwable $e) {
    $tx->rollback();
    throw $e;
}
```

The **transaction reuse pattern** lets you restart the same object without allocating a new one:

```php
$tx = $conn->beginTransaction();

$conn->createCommand('logs')->insertOne(['msg' => 'work unit 1']);
$tx->commit();

// Reuse — calls $tx->beginTransaction() to start a new work unit on the same object
$tx->beginTransaction();
$conn->createCommand('logs')->insertOne(['msg' => 'work unit 2']);
$tx->commit();
```

A supersession guard prevents reactivating a transaction that has been superseded by a newer call to `$conn->beginTransaction()`.

### Collection Gateway (High-Level CRUD)

`TMongoCollectionGateway` wraps a collection name with a CRUD + event API and magic finder methods:

```php
use Prado\Data\DataGateway\TMongoCollectionGateway;

$gw = new TMongoCollectionGateway('users', $conn);

// Magic finders — camelCase field name after findBy/findAllBy/deleteBy
$alice   = $gw->findByEmail('alice@example.com');
$active  = $gw->findAllByActive(true);
$deleted = $gw->deleteByArchived(true);

// Full CRUD
$id = $gw->insert(['name' => 'Eve', 'email' => 'eve@example.com']); // the generated _id
$gw->update(['name' => 'Eve'], ['$set' => ['verified' => true]]);    // filter first, then the update
$gw->delete(['email' => 'eve@example.com']);

// Events
$gw->OnCreateCommand[] = function($sender, $param) {
    // $param->Command is the TMongoCommand being built
};
$gw->OnExecuteCommand[] = function($sender, $param) {
    // Fires after each command execution
};
```

`findById()`, `findAllByIds()`, `updateById()` and `deleteById()` convert a 24-character hex string to a `MongoDB\BSON\ObjectId`, so you can pass raw string IDs from HTTP requests safely.

### Metadata Introspection

The metadata layer inspects each collection's JSON Schema validator (if one exists) and falls back to sampling a document when no schema is defined:

```php
$meta = $conn->getDbMetaData();

// List all collection names in the database
$names = $meta->findTableNames();

// Inspect a collection's schema
$info = $meta->getTableInfo('users');
echo $info->getTableName();         // 'users'
echo $info->getTableFullName();     // 'users' (MongoDB has no schema prefix)
echo $info->getIsView() ? 'view' : 'collection';

foreach ($info->getColumns() as $fieldName => $field) {
    echo "$fieldName: " . $field->getDbType()
        . ($field->getIsPrimaryKey() ? ' [PK]' : '') . PHP_EOL;
}

// Primary keys (always ['_id'] for MongoDB)
$pks = $info->getPrimaryKeys();

// Create a command builder for this collection
$builder = $info->createCommandBuilder($conn);
$cmd = $builder->createFindCommand(['active' => true], [], ['name' => 'asc'], 10, 0);
$reader = $cmd->query();
```

---

## Command Builder Reference

`TMongoCommandBuilder` implements `IDataCommandBuilder`:

| Method | Description |
|---|---|
| `createFindCommand($filter, $params, $ordering, $limit, $offset, $select)` | Find documents; `$params`, when not empty, are merged into the filter |
| `createCountCommand($filter, $params)` | Count matching documents |
| `createInsertCommand($data)` | Insert one document |
| `createInsertOrIgnoreCommand($data)` | Insert; skip on duplicate `_id` |
| `createUpsertCommand($data, $update, $conflict)` | `updateOne` with `upsert`, matched on the `$conflict` fields (default `_id`); `$update` follows `TMysqlCommandBuilder` and is written with `$set`, the rest of `$data` with `$setOnInsert` |
| `createUpdateCommand($data, $filter, $params)` | `$set` the data on all matching documents |
| `createDeleteCommand($filter, $params)` | Delete matching documents |
| `applyLimitOffset($sql, $limit, $offset)`, `applyOrdering($sql, $ordering)` | No-ops that return `$sql` unchanged; the find command takes limit, skip and sort as options (`applyLimitSkip()` and `applySort()` fill an options array) |
| `getSearchExpression($fields, $keywords)` | A JSON-encoded `$regex` `$or` filter (`getSearchFilter()` returns it as an array) |
| `createFindCommandFromCriteria()`, `createCountCommandFromCriteria()`, `createUpdateCommandFromCriteria()`, `createDeleteCommandFromCriteria()` | Build the command from an `IDataCriteria` |
| `createPrimaryKeyCriteria()`, `createIndexCriteria()`, `createIndexedFindCommand()` | Return `TMongoQueryCriteria` filters for `_id` and relation batch-loading |
| `setDbConnection($conn)`, `getLastInsertID()` | The connection of the builder; the last `_id` an insert recorded on the connection |

The `$filter` argument is a MongoDB filter array, a JSON object string, or `'1=1'` (or empty) to match every document. Other SQL text raises a `TDbException` (`mongocommandbuilder_sql_condition_unsupported`) instead of matching every document.

### Criteria

`TMongoQueryCriteria` carries a MongoDB filter document together with the portable ordering, limit, offset and select of `IDataCriteria`. It implements `IDataCriteria` directly and is **not** a `TSqlCriteria`: it has no SQL condition. The framework's `TTableGateway` (PRADO 4.3.3 and 4.4.0) accepts a `TMongoQueryCriteria`, and ActiveRecord passes it through to the command builder.

```php
use Prado\Data\DataGateway\TMongoQueryCriteria;

$criteria = new TMongoQueryCriteria(['status' => 'active']);
$criteria->OrdersBy['created_at'] = 'desc';
$criteria->Limit = 10;
```

A `TSqlCriteria` with a SQL condition string, given to the builder, raises the same `TDbException` (`mongocommandbuilder_sql_condition_unsupported`); it does not match every document. A criteria without a condition matches every document.

---

## PRADO Application Configuration

Register the extension in your PRADO application config:

**application.xml**
```xml
<modules>
  <module id="db" class="Prado\Data\TMongoSourceConfig"
          ConnectionString="mongodb://localhost:27017"
          DatabaseName="myapp" />
</modules>
```

**application.php (array config)**
```php
'modules' => [
    'db' => [
        'class' => 'Prado\Data\TMongoSourceConfig',
        'properties' => [
            'ConnectionString' => 'mongodb://localhost:27017',
            'DatabaseName'     => 'myapp',
        ],
    ],
],
```

`TMongoSourceConfig` extends `TDataSourceConfig`, so `$app->getModule('db')->getDbConnection()` returns a live `TMongoConnection`.

Because `composer.json` also declares `extra.prado.bootstrap` (`Prado\Data\TMongoSourceConfig`), the module can be registered by package name instead, `<module id="belisoful/prado-mongodb" ConnectionString="…" DatabaseName="myapp" />`, without a `class` attribute.

`TMongoSourceConfig` also handles the global `fxDataGetMetaDataClass` event. PRADO's `TDbMetaData::getInstance()` raises it on the connection, with the driver name as the parameter. For the `'mongo'` driver the handler returns the `TMongoMetaData` class name, and PRADO instantiates it with the connection. The event is needed only when `TDbMetaData::getInstance($conn)` is called directly (it throws when no `TMongoSourceConfig` is registered or listening): `$conn->getDbMetaData()` builds the metadata directly, and `TTableGateway` and ActiveRecord (`TActiveRecordGateway`) call it, on PRADO 4.3.3 and 4.4.0 alike, so neither needs a listener.

It also handles `fxActiveRecordScaffoldInputClass`: for the `'mongo'` driver the handler returns the `TMongoScaffoldInput` class name. ActiveRecord raises that event on the connection before its built-in driver switch (PRADO 4.3.3 and `prado-activerecord` for 4.4), so with the module registered (or listening) the scaffold uses `TMongoScaffoldInput`.

---

## Data Reader

`TMongoDataReader` eagerly buffers all rows from the MongoDB cursor on construction. This allows accurate `getRowCount()` reporting and multi-pass `readAll()` access.

```php
$reader = $cmd->query();

echo $reader->getRowCount(); // accurate — all rows already in memory

// Iterator protocol (foreach)
foreach ($reader as $index => $row) { ... }

// Explicit row-by-row read
while ($row = $reader->read()) { ... }

// Bulk read — returns all remaining rows as an array
$all = $reader->readAll();

// Closing the reader releases the internal buffer
$reader->close();
echo $reader->getIsClosed(); // true
```

The reader is forward-only: the first `rewind()` (the start of a `foreach`) succeeds, and a second `rewind()` on the same reader throws `TDbException('dbdatareader_rewind_invalid')`, consistent with the SQL data reader.

---

## Error Keys

The messages of this extension are in `src/errorMessages.txt`, which `composer.json` registers as `extra.prado.error-messages` (`TMongoConnection` also registers it):

| Key | Meaning |
|---|---|
| `mongoconnection_extension_required` | `ext-mongodb` is not loaded |
| `mongoconnection_open_failed` | Failed to establish the MongoDB connection |
| `mongoconnection_connection_inactive` | Method called on an inactive connection |
| `mongoconnection_missing_database` | `DatabaseName` is not set before a collection is accessed |
| `mongocommand_execute_failed` | A command failed to execute |
| `mongocommand_query_failed` | A query failed to execute |
| `mongotransaction_inactive` | `commit()` or `rollback()` on an inactive transaction |
| `mongometadata_no_collections` | No collections found in the target database |
| `mongocommandbuilder_sql_condition_unsupported` | A SQL condition was given to the MongoDB command builder |
| `mongoquerycriteria_parameters_invalid` | `TMongoQueryCriteria` parameters were neither an array nor an `ArrayAccess` |
| `dbtransaction_transaction_superseded` | A transaction object was reactivated after a newer one superseded it |

`dbdatareader_rewind_invalid` and `dbconnection_active_transaction` are framework keys.

---

## Development

The manifest resolves `pradosoft/prado` from a sibling checkout at `../prado` (a Composer `path` repository). The branch checked out there decides the PRADO line under test: `prado-4.3` is 4.3.3, `master` is 4.4.0. PRADO 4.4 needs ActiveRecord for the tests: add it with `composer require --dev pradosoft/prado-activerecord:dev-main`.

### Running Tests

```sh
composer install
composer unittest            # vendor/bin/phpunit --testsuite unit
```

Live tests that require a running MongoDB instance read connection details from environment variables:

| Variable | Default | Description |
|---|---|---|
| `MONGODB_URI` | `mongodb://localhost:27017` | MongoDB connection URI |
| `MONGODB_DATABASE` | `prado_unitest` | Database to use for tests |

The test bootstrap seeds that database with `tests/initdb_mongodb.js` through `mongosh` when `mongosh` is installed. Tests call `markTestSkipped()` when the `mongodb` extension is missing or the server is unreachable, and the ActiveRecord tests skip when the ActiveRecord package is absent, so the suite passes without a live database.

`.github/workflows/prado-mongo.yml` runs a matrix of PRADO 4.3.3 (`prado-4.3`) on PHP 8.1 and 8.3 and PRADO 4.4.0 (`master`) on PHP 8.2 and 8.4. Each job checks out the framework as the sibling `prado` directory, starts a `mongo:7` service, runs `composer validate --strict`, php-cs-fixer and phpstan, adds `pradosoft/prado-activerecord:dev-main` on the 4.4 jobs only, installs `mongosh` to seed `tests/initdb_mongodb.js`, and runs `composer unittest` with `MONGODB_URI=mongodb://localhost:27017` and `MONGODB_DATABASE=prado_unitest`; the job fails if any test was skipped. It also runs weekly.

### Code Quality

```sh
# Static analysis
vendor/bin/phpstan analyse src/ --memory-limit=512M

# Code style check (dry-run)
vendor/bin/php-cs-fixer fix --dry-run src/

# Code style apply
vendor/bin/php-cs-fixer fix src/
```

### Full Pre-Commit Check

```sh
php -l src/**/*.php                      # 1. Syntax
vendor/bin/php-cs-fixer fix src/         # 2. Style
vendor/bin/phpstan analyse src/          # 3. Types
vendor/bin/phpunit --testsuite unit      # 4. Tests
```

All four checks must pass before committing.

---

## License

[MIT](LICENSE)

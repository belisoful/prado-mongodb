# PRADO MongoDB Extension

A native MongoDB integration for the [PRADO PHP Framework](https://github.com/pradosoft/prado) (version 4.3.3+), implemented as a PRADO 4 extension. Provides a full data-access layer — connection, command, data reader, transaction, metadata, command builder, and collection gateway — all conforming to the PRADO `IData*` interface family.

## Requirements

- PHP 8.1 or higher
- [ext-mongodb](https://pecl.php.net/package/mongodb) (PECL `mongodb` extension)
- [mongodb/mongodb](https://github.com/mongodb/mongo-php-library) PHP library `^2.1` (installed via Composer)
- PRADO Framework `^4.3.3` (dev dependency — not required at runtime by consumers)

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
├── Data/
│   ├── IDataCommand.php          # Interface: execute, query, queryRow, queryScalar, …
│   ├── IDataConnection.php       # Interface: getActive, createCommand, beginTransaction, …
│   ├── IDataReader.php           # Interface: Iterator + read, readAll, close, getRowCount
│   ├── IDataTransaction.php      # Interface: commit, rollback, beginTransaction (reuse)
│   ├── TMongoConnection.php      # Wraps MongoDB\Driver\Manager
│   ├── TMongoCommand.php         # Builder + direct-API facade over MongoDB operations
│   ├── TMongoDataReader.php      # Eagerly-buffered cursor reader implementing IDataReader
│   ├── TMongoTransaction.php     # Wraps a MongoDB client session + transaction
│   ├── TMongoSourceConfig.php    # PRADO bootstrap / TDataSourceConfig subclass
│   └── Common/
│       ├── IDataCommandBuilder.php
│       ├── IDataMetaData.php
│       ├── IDataTableInfo.php
│       └── Mongo/
│           ├── TMongoMetaData.php        # Inspects JSON Schema validators; samples docs
│           ├── TMongoCollectionInfo.php  # IDataTableInfo for a MongoDB collection
│           ├── TMongoFieldInfo.php       # Per-field BSON→PHP type information
│           └── TMongoCommandBuilder.php  # IDataCommandBuilder for MongoDB
├── DataGateway/
│   └── TMongoCollectionGateway.php  # High-level CRUD + magic finders
└── errorMessages.txt             # Error-key → human-readable message map
```

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
$active = $cmd->findMany(['active' => true], sort: ['name' => 1]);

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

Available operations: `OP_FIND`, `OP_INSERT`, `OP_UPDATE`, `OP_DELETE`, `OP_AGGREGATE`, `OP_COUNT`, `OP_DISTINCT`.

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
$gw->insert(['name' => 'Eve', 'email' => 'eve@example.com']);
$gw->update(['$set' => ['verified' => true]], ['name' => 'Eve']);
$gw->delete(['email' => 'eve@example.com']);

// Events
$gw->OnCreateCommand[] = function($sender, $param) {
    // $param->Command is the TMongoCommand being built
};
$gw->OnExecuteCommand[] = function($sender, $param) {
    // Fires after each command execution
};
```

`normalizeId()` automatically converts 24-character hex strings to `MongoDB\BSON\ObjectId` in filter values, so you can pass raw string IDs from HTTP requests safely.

### Metadata Introspection

The metadata layer inspects each collection's JSON Schema validator (if one exists) and falls back to sampling a document when no schema is defined:

```php
$meta = $conn->getDbMetaData();

// List all collection names in the database
$names = $meta->findTableNames();

// Inspect a collection's schema
$info = $meta->getTableInfo('users');
echo $info->getTableName();         // 'users'
echo $info->getTableFullName();     // 'myapp.users'
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
| `createFindCommand($filter, $params, $ordering, $limit, $offset, $select)` | Find documents |
| `createCountCommand($filter, $params)` | Count matching documents |
| `createInsertCommand($data)` | Insert one document |
| `createInsertOrIgnoreCommand($data)` | Insert; skip on duplicate `_id` |
| `createUpsertCommand($data, $update, $conflict)` | Replace or insert by `_id` |
| `createUpdateCommand($data, $filter, $params)` | Update matching documents |
| `createDeleteCommand($filter, $params)` | Delete matching documents |
| `applyLimitOffset($cmd, $limit, $offset)` | Attach limit/skip |
| `applyOrdering($cmd, $ordering)` | Attach sort (`asc`/`desc` → `1`/`-1`) |
| `getSearchExpression($fields, $keywords)` | Build a `$regex` OR filter |

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

`TMongoSourceConfig` extends `TDataSourceConfig` and registers the global `fxDataGetMetaDataInstance` event handler, making `$app->getModule('db')->getDbConnection()` return a live `TMongoConnection`.

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

Attempting to `rewind()` a reader that has already yielded at least one row throws `TDbException('dbdatareader_rewind_invalid')`, enforcing forward-only semantics consistent with the SQL data reader.

---

## Error Keys

All exceptions thrown by this extension use string error keys mapped in `src/errorMessages.txt`:

| Key | Meaning |
|---|---|
| `dbdatareader_rewind_invalid` | Attempted to rewind a reader already past its first row |
| `mongometadata_no_collections` | No collections found in the target database |

---

## Development

### Running Tests

```sh
composer install
vendor/bin/phpunit --testsuite unit
```

Live tests that require a running MongoDB instance read connection details from environment variables:

| Variable | Default | Description |
|---|---|---|
| `MONGODB_URI` | `mongodb://localhost:27017` | MongoDB connection URI |
| `MONGODB_DATABASE` | `prado_unitest` | Database to use for tests |

Tests call `markTestSkipped()` when the server is unreachable, so the suite always passes without a live database.

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

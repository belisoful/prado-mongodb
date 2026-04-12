/**
 * MongoDB test database schema for prado unit tests.
 *
 * Mirrors the structure of initdb_firebird.sql / initdb_mysql.sql so that
 * the same field names can be used in cross-database tests.
 *
 * Run with mongosh (from the host):
 *   mongosh "mongodb://localhost:27017/prado_unitest" tests/initdb_mongodb.js
 *
 * Or inside the mongo:7 Docker container (CI / docker exec):
 *   mongosh "mongodb://localhost:27017/prado_unitest" --quiet --file /tmp/initdb_mongodb.js
 *
 * Strategy: create collection → insert seed data → attach validator via collMod.
 * This avoids BSON type-coercion surprises during the seed insert while still
 * making the full JSON Schema visible to listCollections (and therefore to
 * TMongoMetaData / MongoColumnTest).
 *
 * The script is idempotent: it drops and recreates each collection.
 */

// db is already set to prado_unitest by the URI.  getSiblingDB is a no-op
// safety net when the script is invoked without a database in the URI.
db = db.getSiblingDB('prado_unitest');

// ================================================================
// table1
// ================================================================

db.table1.drop();
db.createCollection('table1');           // no validator yet
db.table1.createIndex({ name: 1 });

// Seed document — inserted before the validator is attached so BSON type
// coercion by the shell can never cause a spurious validation failure.
db.table1.insertOne({
	name: 'test',
	field1_int: NumberInt(0),
	field4_double: 10.0,
	field5_double: 0.0,
	field6_date: new Date(),
	field7_string: '00:00:00',
	field8_int: NumberLong('0'),
	field10_bool: false,
});

// Attach the JSON Schema validator after the seed insert.
// TMongoMetaData reads this via listCollections.
db.runCommand({
	collMod: 'table1',
	validator: {
		$jsonSchema: {
			bsonType: 'object',
			required: [
				'name', 'field1_int', 'field4_double', 'field5_double',
				'field6_date', 'field7_string', 'field8_int', 'field10_bool',
			],
			properties: {
				name: {
					bsonType: 'string',
					description: 'display name, required',
				},
				field1_int: {
					bsonType: 'int',
					description: 'small integer field',
				},
				field2_string: {
					bsonType: 'string',
					description: 'optional varchar field',
				},
				field3_date: {
					bsonType: 'date',
					description: 'optional date field',
				},
				field4_double: {
					bsonType: 'double',
					description: 'float / double field',
				},
				field5_double: {
					bsonType: 'double',
					description: 'double precision field',
				},
				field6_date: {
					bsonType: 'date',
					description: 'timestamp field',
				},
				field7_string: {
					bsonType: 'string',
					description: 'time stored as HH:MM:SS string',
				},
				field8_int: {
					bsonType: 'long',
					description: 'bigint field',
				},
				field9_string: {
					bsonType: 'string',
					description: 'optional char field',
				},
				field10_bool: {
					bsonType: 'bool',
					description: 'boolean field',
				},
				field11_string: {
					bsonType: 'string',
					description: 'optional text / blob field',
				},
			},
		},
	},
	validationLevel: 'strict',
	validationAction: 'error',
});

// ================================================================
// address
// ================================================================

db.address.drop();
db.createCollection('address');          // no validator yet
db.address.createIndex({ username: 1 }, { unique: true });

db.address.insertOne({
	username: 'wei',
	phone: '1111111',
	field1_bool: false,
	field2_date: new Date(),
	field3_double: 0.0,
	field4_int: NumberInt(0),
	field6_string: '00:00:00',
	field7_date: new Date(),
	field8_decimal: NumberDecimal('0.0000'),
	field9_decimal: NumberDecimal('0.0000'),
});

db.runCommand({
	collMod: 'address',
	validator: {
		$jsonSchema: {
			bsonType: 'object',
			required: [
				'username', 'phone', 'field1_bool', 'field2_date',
				'field3_double', 'field4_int', 'field6_string', 'field7_date',
				'field8_decimal', 'field9_decimal',
			],
			properties: {
				username: {
					bsonType: 'string',
					description: 'primary identifier, must be unique',
				},
				phone: {
					bsonType: 'string',
					description: 'phone number',
				},
				field1_bool: {
					bsonType: 'bool',
					description: 'boolean field',
				},
				field2_date: {
					bsonType: 'date',
					description: 'date field',
				},
				field3_double: {
					bsonType: 'double',
					description: 'double precision field',
				},
				field4_int: {
					bsonType: 'int',
					description: 'integer / foreign-key field',
				},
				field5_string: {
					bsonType: 'string',
					description: 'optional text field',
				},
				field6_string: {
					bsonType: 'string',
					description: 'time stored as HH:MM:SS string',
				},
				field7_date: {
					bsonType: 'date',
					description: 'timestamp field',
				},
				field8_decimal: {
					bsonType: 'decimal',
					description: 'decimal(19,4) stored as Decimal128',
				},
				field9_decimal: {
					bsonType: 'decimal',
					description: 'numeric(10,4) stored as Decimal128',
				},
				int_fk1: {
					bsonType: 'int',
					description: 'foreign key reference 1',
				},
				int_fk2: {
					bsonType: 'int',
					description: 'foreign key reference 2',
				},
			},
		},
	},
	validationLevel: 'strict',
	validationAction: 'error',
});

print('MongoDB schema initialised: prado_unitest.table1, prado_unitest.address');

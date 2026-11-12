<?php
/**
 * A few common settings for all unit tests.
 *
 * Also remember do define the @package attribute for your test class to make it appear under
 * the right package in unit test and code coverage reports.
 */

$composerLoader = require(__DIR__ . '/../../vendor/autoload.php');
require_once(__DIR__ . '/../../vendor/pradosoft/prado/framework/Prado.php');

// PRADO's test harness (PradoUnit, TTestApplication, ...) lives under the
// framework's autoload-dev namespace Prado\Test\Unit\, which Composer does not
// load for a dependency, so it has been registered here against the framework's
// tests/unit directory.
$composerLoader->addPsr4('Prado\\Test\\Unit\\', __DIR__ . '/../../vendor/pradosoft/prado/tests/unit/');

require_once(__DIR__ . '/MongoActiveRecordCapabilityTrait.php');

// Boot a test application so the global (fx) event bus has an application
// context, then register TMongoSourceConfig as a module on it.  This mirrors a
// real PRADO application declaring `<module class="...TMongoSourceConfig"/>`:
// the application holds the module strongly (the global fx handler list is a
// TWeakCallableCollection, so a strongly-held, listening module is required)
// and the module's fx* handlers (fxDataGetMetaDataClass,
// fxActiveRecordScaffoldInputClass) resolve the 'mongo' driver for the
// framework's TDbMetaData / scaffold factories.
$application = new \Prado\Test\Unit\Harness\TTestApplication();
$mongoSourceConfig = new \Prado\Data\TMongoSourceConfig();
$application->setModule('mongo-source', $mongoSourceConfig);
$mongoSourceConfig->listen();
$GLOBALS['_pradoApplication'] = $application;
$GLOBALS['_pradoMongoSourceConfig'] = $mongoSourceConfig;

/**
 * Seeds the MongoDB test database before the suite runs.
 *
 * Each invocation re-runs tests/initdb_mongodb.js against the configured test
 * database via mongosh, so the suite is self-contained and does not depend on
 * an external setup step. Override the connection via the MONGODB_URI and
 * MONGODB_DATABASE env vars.
 */
(static function (): void {
	if (!extension_loaded('mongodb')) {
		return;
	}

	$uri = getenv('MONGODB_URI') ?: 'mongodb://localhost:27017';
	$db  = getenv('MONGODB_DATABASE') ?: 'prado_unitest';
	$script = __DIR__ . '/../initdb_mongodb.js';
	if (!is_file($script)) {
		return;
	}

	$mongosh = trim((string) @shell_exec('command -v mongosh 2>/dev/null'));
	if ($mongosh === '') {
		fwrite(STDERR, "[bootstrap] mongosh not found; skipping MongoDB seed.\n");
		return;
	}

	$cmd = sprintf(
		'%s %s --quiet %s 2>&1',
		escapeshellcmd($mongosh),
		escapeshellarg(rtrim($uri, '/') . '/' . $db),
		escapeshellarg($script)
	);
	$output = [];
	$status = 0;
	exec($cmd, $output, $status);
	if ($status !== 0) {
		fwrite(STDERR, "[bootstrap] MongoDB seed failed: " . implode("\n", $output) . "\n");
	}
})();

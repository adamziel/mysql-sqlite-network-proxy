<?php
/**
 * A MySQL<->SQLite proxy that parses MySQL queries and transforms them into SQLite operations.
 *
 * Most queries works, and the upcoming translation driver should bring the parity much
 * closer to 100%: https://github.com/WordPress/sqlite-database-integration/pull/157
 */

require_once __DIR__ . '/mysql-server.php';
require_once __DIR__ . '/handler-sqlite-translation.php';

$server = new MySQLSocketServer(
	new SQLiteTranslationHandler(__DIR__ . '/database/test.db'),
	['port' => 3306]
);
$server->start();


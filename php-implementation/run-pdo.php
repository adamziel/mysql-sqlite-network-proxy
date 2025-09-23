<?php
/**
 * A naive MySQL<->SQLite proxy that just passes all the MySQL queries to the SQLite database.
 * Most queries will fail, but some will work.
 */

require_once __DIR__ . '/mysql-server.php';
require_once __DIR__ . '/handler-pdo.php';

$db_path = __DIR__ . '/database/test.db';
$server = new MySQLSocketServer(
	new PDOHandler(new PDO("sqlite:$db_path")),
	['port' => 3316]
);
$server->start();

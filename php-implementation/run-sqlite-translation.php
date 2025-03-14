<?php

require_once __DIR__ . '/mysql-server.php';
require_once __DIR__ . '/handler-sqlite-translation.php';

$server = new MySQLSocketServer(
	new SQLiteTranslationHandler(__DIR__ . '/database/test.db'),
	['port' => 3306]
);
$server->start();


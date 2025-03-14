<?php

require_once __DIR__ . '/mysql-server.php';
require_once __DIR__ . '/wp-sqlite-gateway.php';

if (!isset($argv[1])) {
    die("\033[31mError:\033[0m WordPress path must be provided as first positional argument\n\n" .
        "\033[1mUsage:\033[0m php run-wpdb.php <wordpress-path>\n" .
        "\033[2mExample: php run-wpdb.php /var/www/wordpress\033[0m\n");
}

$wp_path = rtrim($argv[1], '/');
require_once $wp_path . '/wp-load.php';

$server = new MySQLSocketServer(new WPDBSQLiteHandler($wpdb), ['port' => 3306]);
$server->start();

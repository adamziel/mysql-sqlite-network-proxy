<?php

try {
    $pdo = new PDO("mysql:host=127.0.0.1;port=3316;dbname=test", "root", "test");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

$result = $pdo->exec("DROP TABLE IF EXISTS wptests_users");
$result = $pdo->exec("
CREATE TABLE wptests_users (
	ID bigint(20) unsigned NOT NULL auto_increment,
	decimal_column DECIMAL(10,2) NOT NULL DEFAULT 0,
	float_column FLOAT(10,2) NOT NULL DEFAULT 0,
	enum_column ENUM('a', 'b', 'c') NOT NULL DEFAULT 'a',
	date_column DATE NOT NULL,
	PRIMARY KEY  (ID)
)
");

$result = $pdo->exec("INSERT INTO wptests_users (decimal_column, float_column, enum_column, date_column) VALUES (123.45, 678.90, 'a', '2024-02-14')");
$result = $pdo->exec("INSERT INTO wptests_users (decimal_column, float_column, enum_column, date_column) VALUES (987, 321, 'b', '2024-02-14')");

$stmt = $pdo->prepare("SELECT * FROM wptests_users WHERE ID > :id");
$stmt->execute(['id' => 2]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

var_dump($row);


$stmt = $pdo->prepare("SELECT * FROM wptests_users WHERE ID > :id");
$stmt->execute(['id' => 0]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

var_dump($row);

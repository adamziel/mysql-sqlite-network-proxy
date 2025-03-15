# MySQL <-> SQLite network proxy – PHP edition

A very simple example of how WordPress Playground may run all MySQL queries in SQLite, even if they came from a plugin that started its own MySQL connection.

This example is 100% PHP and dependency–free. It can run both on a PHP host or in any Playground runtime.

## Implementation overview

The most interesting code is in the [mysql-server.php](./mysql-server.php) file.

Here's how the `Network -> Decoded query -> Query results -> Network` pipeline works:

* The `MySQLProtocol` class handles decoding and encoding of MySQL protocol packets.
* The `MySQLGateway` class accepts and outputs encoded MySQL protocol bytes via transport-agnostic methods such as `receiveBytes()`. It also accepts a query handler instance.
* The `MySQLSocketServer` class accepts multiple concurrent network connections, creates a `MySQLGateway` for each, and passes the bytes between the network and its gateway.

This repo ships a few examples running different query handlers:

* `run-sqlite-translation.php` executes MySQL queries as SQLite operations using the query parsing and translation machinery from the [sqlite-database-integration](https://github.com/WordPress/sqlite-database-integration) WordPress plugin.
* `run-pdo.php` passes the incoming MySQL queries straight to a PDO SQLite instance. As SQL dialects have significant differences, some queries work, but most don't.
* `run-mock-data.php` always responds with the same results

You could get arbitrarily fancy with these query handlers. Want to run MySQL queries on PostgreSQL? Or MongoDB? You're just one class away from being able to do that. Be sure to [read this discussion](https://github.com/WordPress/sqlite-database-integration/pull/157) if you decide to go down that path.

## Running the demo

Run the MySQL <-> SQLite proxy server

```bash
cd php-implementation
php run-sqlite-translation.php
```

Run the MySQL client:

```bash
php client.php
```

## Limitations

* We only reply with a resultset (for SELECT) or an OK packet (for other queries). Error packets are not yet implemented. Nuances of the query-specific expected response format are not yet implemented, too.
* `mysql` CLI client is unable to connect to the proxy server due to `mysql2` library limitations.

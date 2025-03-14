# MySQL <-> SQLite network proxy – Isomorphic TypeScript edition

A very simple example of how WordPress Playground may run all MySQL queries in SQLite, even if they came from a plugin that started its own MySQL connection.

This example is dependency-free and can run both in Node.js and in a web browser.

## How it works

* The `MySQLProtocolConnection` class accepts and outputs data via ReadableStream and WritableStream. It also accepts an arbitrary query handler function.
* `mysql-server.ts` starts a server on port 3306 and pipes the network socket to a `MySQLProtocolConnection` instance.
* We use a query handler that runs a long-running WordPress process with the [sqlite-database-integration](https://github.com/WordPress/sqlite-database-integration) plugin enabled
* PHP suspends itself and waits for a MySQL query via a `post_message_to_js` call
* On incoming MySQL query PHP runtime is resumed. It translates and runs the query via `$wpdb`
* PHP returns a data structure in a MySQL-compatible format, `MySQLProtocolConnection` encodes it into MySQL Protocol binary format, and finally the network server passes it back to the client

## Running the demo

Install all dependencies:

```bash
npm install
```

Run the MySQL <-> SQLite proxy server

```bash
cd typescript-isomorphic
bun mysql-server.ts 

# Or, if you want to store the database file in a local directory:
bun mysql-server.ts /path/to/empty/directory
```

Finally, run the MySQL client:

```bash
php client.php
```

## Limitations

* We only reply with a resultset (for SELECT) or an OK packet (for other queries). Error packets are not yet implemented. Nuances of the query-specific expected response format are not yet implemented, too.
* `mysql` CLI client is unable to connect to the proxy server due to `mysql2` library limitations.

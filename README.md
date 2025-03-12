# WordPress Playground MySQL <-> SQLite network proxy

A very simple example of how WordPress Playground may run all MySQL queries in SQLite,
even if they came from a plugin that started its own MySQL connection.

## How it works

* JavaScript runs a `mysql2` server on port 3306 and listens for queries
* JavaScript also runs a long-running WordPress process with the `sqlite-database-integration`
  plugin enabled
* PHP suspends itself and waits for a MySQL query via a `post_message_to_js` call
* On incoming MySQL query PHP runtime is resumed. It translates and runs the query via `$wpdb`
* PHP returns a data structure in a MySQL-compatible format
* The JavaScript `mysql2` server receives the resultset and returns it to the client

## Running the demo

Install all dependencies:

```bash
npm install
```

Run the MySQL <-> SQLite proxy server

```bash
bun mysql-server.ts 

# Or, if you want to store the database file in a local directory:
bun mysql-server.ts /path/to/empty/directory
```

Finally, run the MySQL client:

```bash
php client.php
```

## Limitations

– It's node.js only for now. We'd need to fork `mysql2` and build an isomorphic server class.
– We only reply with a resultset (for SELECT) or an OK packet (for other queries). Error packets are not yet implemented. Nuances of the query-specific expected response format are not yet implemented, too.
- `mysql` CLI client is unable to connect to the proxy server due to `mysql2` library limitations.

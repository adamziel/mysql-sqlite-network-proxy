<?php

define('WP_DEBUG', false);

require_once __DIR__ . '/sqlite-database-integration/version.php';
require_once __DIR__ . '/sqlite-database-integration/wp-includes/parser/class-wp-parser-grammar.php';
require_once __DIR__ . '/sqlite-database-integration/wp-includes/parser/class-wp-parser.php';
require_once __DIR__ . '/sqlite-database-integration/wp-includes/parser/class-wp-parser-node.php';
require_once __DIR__ . '/sqlite-database-integration/wp-includes/parser/class-wp-parser-token.php';
require_once __DIR__ . '/sqlite-database-integration/wp-includes/mysql/class-wp-mysql-token.php';
require_once __DIR__ . '/sqlite-database-integration/wp-includes/mysql/class-wp-mysql-lexer.php';
require_once __DIR__ . '/sqlite-database-integration/wp-includes/mysql/class-wp-mysql-parser.php';
require_once __DIR__ . '/sqlite-database-integration/wp-includes/sqlite/class-wp-sqlite-pdo-user-defined-functions.php';
require_once __DIR__ . '/sqlite-database-integration/wp-includes/sqlite-ast/class-wp-sqlite-connection.php';
require_once __DIR__ . '/sqlite-database-integration/wp-includes/sqlite-ast/class-wp-sqlite-configurator.php';
require_once __DIR__ . '/sqlite-database-integration/wp-includes/sqlite-ast/class-wp-sqlite-driver.php';
require_once __DIR__ . '/sqlite-database-integration/wp-includes/sqlite-ast/class-wp-sqlite-driver-exception.php';
require_once __DIR__ . '/sqlite-database-integration/wp-includes/sqlite-ast/class-wp-sqlite-information-schema-builder.php';
require_once __DIR__ . '/sqlite-database-integration/wp-includes/sqlite-ast/class-wp-sqlite-information-schema-exception.php';
require_once __DIR__ . '/sqlite-database-integration/wp-includes/sqlite-ast/class-wp-sqlite-information-schema-reconstructor.php';


class SQLiteTranslationHandler implements MySQLQueryHandler {
	/** @var WP_SQLite_Driver */
	private $sqlite_driver;

	public function __construct($sqlite_database_path) {
		define('FQDB', $sqlite_database_path);
		define('FQDBDIR', dirname(FQDB) . '/');

		$this->sqlite_driver = new WP_SQLite_Driver(
			new WP_SQLite_Connection( array( 'path' => $sqlite_database_path ) ),
			'wordpress'
		);
	}

	public function handleQuery(string $query): MySQLServerQueryResult {
		try {
			// An extremely naive check. We should be using the MySQL parser to
			// determine this:
			if(!str_starts_with(strtolower($query), 'select')) {
				$this->sqlite_driver->query($query);
				return new OkayPacketResult(
					$this->sqlite_driver->get_last_return_value() ?? 0,
					$this->sqlite_driver->get_insert_id() ?? 0
				);
			}

			$rows    = $this->sqlite_driver->query($query, PDO::FETCH_ASSOC);
			$columns = $this->computeColumnInfo();
			return new SelectQueryResult($columns, $rows);
		} catch (Throwable $e) {
			return new ErrorQueryResult($e->getMessage());
		}
	}

	public function computeColumnInfo() {
		$columns = [];

		$column_meta = $this->sqlite_driver->get_last_column_meta();

		$types = [
			'DECIMAL'     => MySQLProtocol::FIELD_TYPE_DECIMAL,
			'TINY'        => MySQLProtocol::FIELD_TYPE_TINY,
			'SHORT'       => MySQLProtocol::FIELD_TYPE_SHORT,
			'LONG'        => MySQLProtocol::FIELD_TYPE_LONG,
			'FLOAT'       => MySQLProtocol::FIELD_TYPE_FLOAT,
			'DOUBLE'      => MySQLProtocol::FIELD_TYPE_DOUBLE,
			'NULL'        => MySQLProtocol::FIELD_TYPE_NULL,
			'TIMESTAMP'   => MySQLProtocol::FIELD_TYPE_TIMESTAMP,
			'LONGLONG'    => MySQLProtocol::FIELD_TYPE_LONGLONG,
			'INT24'       => MySQLProtocol::FIELD_TYPE_INT24,
			'DATE'        => MySQLProtocol::FIELD_TYPE_DATE,
			'TIME'        => MySQLProtocol::FIELD_TYPE_TIME,
			'DATETIME'    => MySQLProtocol::FIELD_TYPE_DATETIME,
			'YEAR'        => MySQLProtocol::FIELD_TYPE_YEAR,
			'NEWDATE'     => MySQLProtocol::FIELD_TYPE_NEWDATE,
			'VARCHAR'     => MySQLProtocol::FIELD_TYPE_VARCHAR,
			'BIT'         => MySQLProtocol::FIELD_TYPE_BIT,
			'NEWDECIMAL'  => MySQLProtocol::FIELD_TYPE_NEWDECIMAL,
			'ENUM'        => MySQLProtocol::FIELD_TYPE_ENUM,
			'SET'         => MySQLProtocol::FIELD_TYPE_SET,
			'TINY_BLOB'   => MySQLProtocol::FIELD_TYPE_TINY_BLOB,
			'MEDIUM_BLOB' => MySQLProtocol::FIELD_TYPE_MEDIUM_BLOB,
			'LONG_BLOB'   => MySQLProtocol::FIELD_TYPE_LONG_BLOB,
			'BLOB'        => MySQLProtocol::FIELD_TYPE_BLOB,
			'VAR_STRING'  => MySQLProtocol::FIELD_TYPE_VAR_STRING,
			'STRING'      => MySQLProtocol::FIELD_TYPE_STRING,
			'GEOMETRY'    => MySQLProtocol::FIELD_TYPE_GEOMETRY,
		];

		foreach ($column_meta as $column) {
			$type = $types[$column['native_type']] ?? null;
			if ( null === $type ) {
				throw new Exception('Unknown column type: ' . $column['native_type']);
			}
			$columns[] = [
				'name'     => $column['name'],
				'length'   => $column['len'],
				'type'     => $type,
				'flags'    => 129,
				'decimals' => $column['precision']
			];
		}
		return $columns;
	}
}




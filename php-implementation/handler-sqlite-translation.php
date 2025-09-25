<?php

define('WP_DEBUG', false);

require_once __DIR__ . '/wpdb-polyfill.php';

// A polyfill – function is called by the wpdb class.
function apply_filters($tag, $value) {
	return $value;
}

// A dummy polyfill – function is called by the wpdb class.
function wp_debug_backtrace_summary( $ignore_class = null, $skip_frames = 0, $pretty = true ) {
	return 'unknown';
}

require_once __DIR__ . '/sqlite-database-integration/version.php';
require_once __DIR__ . '/sqlite-database-integration/wp-includes/sqlite/class-wp-sqlite-lexer.php';
require_once __DIR__ . '/sqlite-database-integration/wp-includes/sqlite/class-wp-sqlite-query-rewriter.php';
require_once __DIR__ . '/sqlite-database-integration/wp-includes/sqlite/class-wp-sqlite-translator.php';
require_once __DIR__ . '/sqlite-database-integration/wp-includes/sqlite/class-wp-sqlite-token.php';
require_once __DIR__ . '/sqlite-database-integration/wp-includes/sqlite/class-wp-sqlite-pdo-user-defined-functions.php';
require_once __DIR__ . '/sqlite-database-integration/wp-includes/sqlite/class-wp-sqlite-db.php';

class SQLiteTranslationHandler implements MySQLQueryHandler {
	private $wpdb;

	public function __construct($sqlite_database_path) {
		define('FQDB', $sqlite_database_path);
		define('FQDBDIR', dirname(FQDB) . '/');
		$this->wpdb = new WP_SQLite_DB('wordpress');
	}

	public function handleQuery(string $query): MySQLServerQueryResult {
		// An extremely naive check. We should be using the MySQL parser to
		// determine this:
		if(!str_starts_with(strtolower($query), 'select')) {
			$this->wpdb->query($query);
			return new OkayPacketResult(
				$this->wpdb->rows_affected ?? 0,
				$this->wpdb->insert_id ?? 0
			);
		}
		$rows = $this->wpdb->get_results($query, ARRAY_A);
		if ($this->wpdb->last_error) {
			return new ErrorQueryResult($this->wpdb->last_error);
		}
		$columns = $this->computeColumnInfo();
		return new SelectQueryResult($columns, $rows);
	}

	public function computeColumnInfo() {
		$columns = [];

		$column_meta = $this->wpdb->get_dbh()->get_last_column_meta();

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
			$type = $types[$column['native_type']];
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




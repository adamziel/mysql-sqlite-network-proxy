<?php

define('WP_DEBUG', false);

require_once __DIR__ . '/wpdb-polyfill.php';

// A polyfill – function is called by the wpdb class.
function apply_filters($tag, $value) {
	return $value;
}

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
		$this->wpdb = new WP_SQLite_DB($sqlite_database_path);
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
		$columns = $this->computeColumnInfo($rows);
		return new SelectQueryResult($columns, $rows);
	}

	public function computeColumnInfo($rows) {
		if (empty($rows)) {
			return [];
		}
	
		$columns = [];
		$firstRow = $rows[0];
		
		foreach ($firstRow as $key => $value) {
			$columnType = 8;  // Default to LONGLONG
			$columnLength = 1;
			$decimals = 0;
			
			// Analyze all rows to find the maximum length and most specific type
			foreach ($rows as $row) {
				$currentValue = $row[$key];
				
				if (is_string($currentValue)) {
					$columnType = 253;  // VARCHAR
					$columnLength = max($columnLength, strlen($currentValue));
				} elseif (is_numeric($currentValue)) {
					if (is_int($currentValue) || $currentValue == (int)$currentValue) {
						if ($columnType != 253) { // Don't override VARCHAR
							$columnType = 3;   // LONG
							$columnLength = 11;
						}
					} else {
						if ($columnType != 253) { // Don't override VARCHAR
							$columnType = 246; // DECIMAL
							$columnLength = 10;
							$decimals = 2;
						}
					}
				}
			}
			
			$columns[] = [
				'name' => $key,
				'length' => $columnLength ?? 1,
				'type' => $columnType,
				'flags' => 129,
				'decimals' => $decimals
			];
		}
		return $columns;
	}
}




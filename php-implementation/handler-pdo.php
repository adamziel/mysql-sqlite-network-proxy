<?php

class PDOHandler implements MySQLQueryHandler {
	private $pdo;

	public function __construct($pdo) {
		$this->pdo = $pdo;
	}

	public function handleQuery(string $query): MySQLServerQueryResult {
		// An extremely naive check. We should be using the MySQL parser to
		// determine this:
		if(!str_starts_with(strtolower($query), 'select')) {
			$this->pdo->exec($query);
			return new OkayPacketResult(
				$this->pdo->rows_affected ?? 0,
				$this->pdo->insert_id ?? 0
			);
		}
		$rows = $this->pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
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
				'catalog' => 'sqlite',
				'schema' => '',
				'table' => '',
				'orgTable' => '',
				'name' => $key,
				'orgName' => '',
				'characterSet' => 63,
				'columnLength' => $columnLength,
				'columnType' => $columnType,
				'flags' => 129,
				'decimals' => $decimals
			];
		}
		return $columns;
	}
}

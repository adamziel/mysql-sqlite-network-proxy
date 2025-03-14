<?php

require_once __DIR__ . '/mysql-server.php';

$server = new MySQLSocketServer(new class implements MySQLQueryHandler {
	public function handleQuery(string $query): MySQLServerQueryResult {
		if(!str_starts_with(strtolower($query), 'select')) {
			return new OkayPacketResult(0, 0);
		}
		// Gather row data
		$columns = [
			[
				'name' => 'text',
				'type' => 0xfd,       // MYSQL_TYPE_VAR_STRING (VAR_STRING/BLOB)&#8203;:contentReference[oaicite:2]{index=2}
				'length' => 255,      // Max length for text
				'flags' => 0x0000,    // No special flags
				'decimals' => 0
			]
		];
		$rows_source = [
			['id' => 1, 'text' => 'hello'],
			['id' => 2, 'text' => 'world']
		];
		$rows = [];
		foreach ($rows_source as $row) {
			$rowData = [];
			foreach ($columns as $colMeta) {
				$colName = $colMeta['name'];
				$rowData[] = $row[$colName] ?? null;
			}
			$rows[] = $rowData;
		}
		return new SelectQueryResult($columns, $rows);
	}
}, ['port' => 3306]);

$server->start();


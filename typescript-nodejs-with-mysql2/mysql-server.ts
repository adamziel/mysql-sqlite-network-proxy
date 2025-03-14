'use strict';

import mysql from 'mysql2';
import { createNodeFsMountHandler, getPHPLoaderModule } from '@php-wasm/node';
import { loadPHPRuntime, PHP } from '@php-wasm/universal';
import { bootWordPress } from '@wp-playground/wordpress';
import fs from 'fs';

const sqliteIntegrationPluginZip = fs.readFileSync(
	'./sqlite-database-integration.zip'
);
const wordpressZip = fs.readFileSync('./wp-6.7.zip');
const runtime = await bootWordPress({
	siteUrl: 'http://playground-domain/',
	createPhpRuntime: async () =>
		await loadPHPRuntime(await getPHPLoaderModule('8.0')),
	sqliteIntegrationPluginZip: new File(
		[sqliteIntegrationPluginZip],
		'sqlite-database-integration.zip',
		{ type: 'application/zip' }
	),
	wordPressZip: new File([wordpressZip], 'wordpress.zip', {
		type: 'application/zip',
	})
});

type InvertedPromise<T> = {
	resolve: (value: T) => void;
	reject: (reason: any) => void;
	promise: Promise<T>;
};

const invertPromise = <T>(): InvertedPromise<T> => {
	let resolve!: (value: T) => void;
	let reject!: (reason: any) => void;
	const promise = new Promise<T>((_resolve, _reject) => {
		resolve = _resolve;
		reject = _reject;
	});
	return { promise, resolve, reject };
};

type QueryResult = {
	rows: any[];
	columns: any[];
	result_type: 'ok' | 'resultset';
};

class WordPressDatabase {
	private php: PHP;
	private queryResolver: InvertedPromise<string>;
	private resultsResolver: InvertedPromise<QueryResult>;

	constructor(php: PHP) {
		this.php = php;
		this.setupMessageHandler();
	}

	private setupMessageHandler() {
		this.php.onMessage(async (message): Promise<any> => {
			const parsedMessage = JSON.parse(message);

			switch (parsedMessage.type) {
				case 'await_query':
					this.queryResolver = invertPromise();
					this.resultsResolver = invertPromise();
					return this.queryResolver.promise;
				case 'query_result':
					this.resultsResolver.resolve(parsedMessage);
					break;
			}
		});

		this.php.run({
			code: `<?php
			require_once '/wordpress/wp-load.php';
		
			function computeColumnInfo($rows) {
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

			while(true) {
				$query = post_message_to_js(json_encode(['type' => 'await_query']));
				// An extremely naive check. We should be using the MySQL parser to
				// determine this:
				if(!str_starts_with(strtolower($query), 'select')) {
					$wpdb->query($query);
					post_message_to_js(json_encode([
						'type' => 'query_result',
						'result_type' => 'ok',
					]));
					continue;
				}
				$rows = $wpdb->get_results($query, ARRAY_A);
				$columns = computeColumnInfo($rows);
				post_message_to_js(json_encode([
					'type' => 'query_result',
					'result_type' => 'resultset',
					'rows' => $rows,
					'columns' => $columns
				]));
			}
			`,
		});
	}

	async runQuery(sql: string): Promise<QueryResult> {
		this.queryResolver.resolve(sql);
		return this.resultsResolver.promise;
	}
}

const php = await runtime.getPrimaryPhp();

// Get mount path from CLI args
const mountPath = process.argv[2];

if (mountPath) {
	// Validate directory exists and contains only .ht.sqlite
	const db = php.readFileAsBuffer('/wordpress/wp-content/database/.ht.sqlite');
	try {
		if (!fs.existsSync(mountPath)) {
			throw new Error(`Directory does not exist: ${mountPath}`);
		}

		const stats = fs.statSync(mountPath);
		if (!stats.isDirectory()) {
			throw new Error(`Path is not a directory: ${mountPath}`);
		}

		const files = fs.readdirSync(mountPath);
		if (files.length > 1 || (files.length === 1 && files[0] !== '.ht.sqlite')) {
			throw new Error(`Directory must be empty or contain only .ht.sqlite file: ${mountPath}`);
		}

		console.log(`Mounting database directory at: ${mountPath}`);
		php.mount('/wordpress/wp-content/database', createNodeFsMountHandler(mountPath));
	} catch (error) {
		console.error(`Error validating mount directory: ${error.message}`);
		process.exit(1);
	}
	php.writeFile('/wordpress/wp-content/database/.ht.sqlite', db);
}

const mysqlToSqliteProxy = new WordPressDatabase(php);

let connectionId = 0;
const server = mysql.createServer((conn) => {
	console.log('got connection!');
});
server.listen(3306);
server.on('connection', (conn) => {
	conn.serverHandshake({
		protocolVersion: 10,
		serverVersion: '5.6.10',
		connectionId: connectionId++,
		statusFlags: 2,
		characterSet: 8,
		capabilityFlags: 0xffffff & ~0x800,
		authCallback: () => {
			conn.writeOk();
			conn.sequenceId = 0;
		},
	});

	conn.sequenceId = 0;

	conn.on('query', async (sql) => {
		try {
			console.log('Running a MySQL query on SQLite:', sql);
			const result = await mysqlToSqliteProxy.runQuery(sql);
			if (result.result_type === 'ok') {
				conn.writeOk();
				conn.sequenceId = 0;
				return;
			} else if (result.result_type === 'resultset') {
				conn.writeTextResult(result.rows, result.columns);
			} else {
				throw new Error('Unknown result type: ' + result.result_type);
			}
		} catch (err) {
			conn.writeError({ code: 1064, message: err.message });
		}
		conn.sequenceId = 0;
	});

	conn.on('quit', () => {
		conn.end();
	});
});

console.log('Listening on port 3306');

import fs from 'fs';
import { createNodeFsMountHandler, getPHPLoaderModule } from '@php-wasm/node';
import { loadPHPRuntime, PHP } from '@php-wasm/universal';
import { bootWordPress } from '@wp-playground/wordpress';
import EventEmitter from 'events';

const sqliteIntegrationPluginZip = fs.readFileSync(
	'../typescript-isomorphic/sqlite-database-integration.zip'
);
const wordpressZip = fs.readFileSync('../typescript-isomorphic/wp-6.7.zip');

const mysqlServerRuntime = await bootWordPress({
	siteUrl: 'http://playground-domain/',
	createPhpRuntime: async () => await loadPHPRuntime(await getPHPLoaderModule('8.0')),
	sqliteIntegrationPluginZip: new File(
		[sqliteIntegrationPluginZip],
		'./typescript-isomorphic/sqlite-database-integration.zip',
		{ type: 'application/zip' }
	),
	wordPressZip: new File([wordpressZip], './typescript-isomorphic/wordpress.zip', {
		type: 'application/zip',
	}),
});
const mysqlClientRuntime = await bootWordPress({
	siteUrl: 'http://playground-client-domain/',
	createPhpRuntime: async () =>
		await loadPHPRuntime(await getPHPLoaderModule('8.0'), {
			websocket: {
				url: (_: any, host: string, port: string) => {
					const query = new URLSearchParams({ host, port }).toString();
					return `ws://playground.internal/?${query}`;
				},
				subprotocol: 'binary',
				decorator: () => MySQLWebSocket,
			},
		}),
	sqliteIntegrationPluginZip: new File(
		[sqliteIntegrationPluginZip],
		'./typescript-isomorphic/sqlite-database-integration.zip',
		{ type: 'application/zip' }
	),
	wordPressZip: new File([wordpressZip], './typescript-isomorphic/wordpress.zip', {
		type: 'application/zip',
	}),
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

type DatabaseState =
	| {
			state: 'awaiting_readiness';
	  }
	| {
			state: 'php_ready_for_command';
			provideCommand: (enqueuedCommand: PHPEnqueuedCommand) => void;
	  }
	| {
			state: 'awaiting_php_response';
			enqueuedCommand: PHPEnqueuedCommand;
	  }
	| {
			state: 'closed';
	  };

type PHPEnqueuedCommand = {
	command: PHPCommand;
	receiveResponseFromPHP: InvertedPromise<string>;
};

type PHPCommand = {
	type: string;
	data?: string | Uint8Array | ArrayBuffer | Buffer;
	[key: string]: any;
};

class PHPMessageServer extends EventEmitter {
	public state: DatabaseState = { state: 'awaiting_readiness' };
	private php: PHP;
	private enqueuedCommands: PHPEnqueuedCommand[] = [];

	constructor(php: PHP, phpScript: string) {
		super();
		this.php = php;
		this.php.onMessage(async (message): Promise<any> => {
			const parsedMessage = JSON.parse(message);
			switch (parsedMessage.type) {
				case 'ready_for_event':
					if (this.state.state === 'awaiting_php_response') {
						// The response isn't coming, let's propagate an empty one.
						this.state.enqueuedCommand.receiveResponseFromPHP.resolve('');
					}
					const sendToPHP = invertPromise();
					this.state = {
						state: 'php_ready_for_command',
						provideCommand: (enqueuedCommand: PHPEnqueuedCommand) => {
							sendToPHP.resolve(JSON.stringify(enqueuedCommand.command));
							this.state = {
								state: 'awaiting_php_response',
								enqueuedCommand,
							};
						},
					};
					this.processNextEvent();
					return sendToPHP.promise;
				case 'response_from_php':
					if (this.state.state !== 'awaiting_php_response') {
						throw new Error('Received response from PHP but not awaiting a response');
					}
					this.state.enqueuedCommand.receiveResponseFromPHP.resolve(parsedMessage.data);
					this.state = {
						state: 'awaiting_readiness',
					};
					break;
			}
		});

		this.php.run({
			code: phpScript,
		});
	}

	async sendCommand(command: PHPCommand): Promise<string> {
		const enqueuedCommand = {
			command,
			receiveResponseFromPHP: invertPromise<string>(),
		};
		this.enqueuedCommands.push(enqueuedCommand);
		this.processNextEvent();
		return enqueuedCommand.receiveResponseFromPHP.promise;
	}

	private processNextEvent() {
		if (this.state.state === 'php_ready_for_command') {
			const enqueuedCommand = this.enqueuedCommands.shift();
			if (enqueuedCommand) {
				this.state.provideCommand(enqueuedCommand);
			}
		}
	}
}

// Fire up a simple PHP server inside the WASM environment
const mysqlServerInstance = await mysqlServerRuntime.getPrimaryPhp();
mysqlServerInstance.mkdir('/wordpress/wp-content/plugins/mysql-server');
await mysqlServerInstance.mount(
	'/wordpress/wp-content/plugins/mysql-server',
	createNodeFsMountHandler(import.meta.dirname)
);
const WordPressDatabase = new PHPMessageServer(
	mysqlServerInstance,
	`<?php
	$dir = '/wordpress/wp-content/plugins/mysql-server';
	require_once $dir . '/mysql-server.php';
	require_once $dir . '/handler-sqlite-translation.php';

	$server = new MySQLPlaygroundYieldServer(
		new SQLiteTranslationHandler('/wordpress/wp-content/database/.ht.sqlite'),
		['port' => 3306]
	);
	$server->start();
	`
);

console.log('Mounted mysql-server');
class MySQLWebSocket {
	static maxClientId = 1;
	clientId = 0;
	readyState: number = 0;
	binaryType: 'arraybuffer';
	listeners: Map<string, Set<Function>>;
	database: PHPMessageServer;

	constructor(options: any, options2: any) {
		console.log('MySQLWebSocket constructor', options, options2);
		this.clientId = MySQLWebSocket.maxClientId++;
		this.listeners = new Map();
		this.database = WordPressDatabase;
		this.readyState = 0;
		this.binaryType = 'arraybuffer';

		// Wait 100ms and emit open event
		this.readyState = 1;
		this.emit('open');

		this.sendJsonCommand({ type: 'new_connection' });
	}

	/**
	 *
	 * @param data
	 */
	send(data: any) {
		this.sendJsonCommand({
			type: 'data_received',
			data: Buffer.from(data).toString('base64'),
		});
	}

	async sendJsonCommand(command: {
		type: string;
		data?: string | Uint8Array | ArrayBuffer | Buffer;
	}) {
		const response = await this.database.sendCommand({
			clientId: this.clientId,
			...command,
		});
		try {
			this.emit('message', Buffer.from(response, 'base64'));
		} catch (error) {
			console.error('Failed to decode base64 data:', error);
			this.emit('message', response);
		}
	}

	on(eventName: string, callback: (e: any) => void) {
		this.addEventListener(eventName, callback);
	}

	once(eventName: string, callback: (e: any) => void) {
		const wrapper = (e: any) => {
			callback(e);
			this.removeEventListener(eventName, wrapper);
		};
		this.addEventListener(eventName, wrapper);
	}

	addEventListener(eventName: string, callback: (e: any) => void) {
		if (!this.listeners.has(eventName)) {
			this.listeners.set(eventName, new Set());
		}
		const listeners = this.listeners.get(eventName);
		if (listeners) {
			listeners.add(callback);
		}
	}

	removeListener(eventName: string, callback: (e: any) => void) {
		this.removeEventListener(eventName, callback);
	}

	removeEventListener(eventName: string, callback: (e: any) => void) {
		const listeners = this.listeners.get(eventName);
		if (listeners) {
			listeners.delete(callback);
		}
	}

	emit(eventName: string, data: any = {}) {
		if (eventName === 'message') {
			this.onmessage(data, true);
		} else if (eventName === 'close') {
			this.onclose(data);
		} else if (eventName === 'error') {
			this.onerror(data);
		} else if (eventName === 'open') {
			this.onopen(data);
		}
		const listeners = this.listeners.get(eventName);
		if (listeners) {
			for (const listener of listeners) {
				if (eventName === 'message') {
					listener(data, true);
				} else {
					listener(data);
				}
			}
		}
	}

	// Default event handlers that can be overridden by the user
	// eslint-disable-next-line @typescript-eslint/no-unused-vars
	onclose(data: any) {}
	// eslint-disable-next-line @typescript-eslint/no-unused-vars
	onerror(data: any) {}
	// eslint-disable-next-line @typescript-eslint/no-unused-vars
	onmessage(data: any, isBinary: boolean) {}
	// eslint-disable-next-line @typescript-eslint/no-unused-vars
	onopen(data: any) {}
}

console.log('Booting mysql-client');

// Create a function to sleep for a specified number of milliseconds
function sleep(ms: number): Promise<void> {
	return new Promise((resolve) => setTimeout(resolve, ms));
}

// Sleep for 2000ms before continuing
await sleep(2000);

console.log('Booted mysql-client');
// Fire up a simple PHP server inside the WASM environment
const mysqlClientInstance = await mysqlClientRuntime.getPrimaryPhp();
console.log('Mounted mysql-client');
const response = await mysqlClientInstance.run({
	code: `<?php

try {
    $pdo = new PDO("mysql:host=127.0.0.1;dbname=test", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage() . PHP_EOL);
}

$result = $pdo->exec("DROP TABLE IF EXISTS wptests_users");
$result = $pdo->exec("
CREATE TABLE wptests_users (
	ID bigint(20) unsigned NOT NULL auto_increment,
	decimal_column DECIMAL(10,2) NOT NULL DEFAULT 0,
	float_column FLOAT(10,2) NOT NULL DEFAULT 0,
	enum_column ENUM('a', 'b', 'c') NOT NULL DEFAULT 'a',
	date_column DATE NOT NULL DEFAULT CURRENT_DATE,
	PRIMARY KEY  (ID),
)
	");
$result = $pdo->exec("INSERT INTO wptests_users (decimal_column, float_column, enum_column, date_column) VALUES (123.45, 678.90, 'bcd', '2024-02-14')");

$stmt = $pdo->prepare("SELECT * FROM wptests_users WHERE ID > :id");
$stmt->execute(['id' => 0]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

var_dump($row);

// Also test with mysqli connection
$mysqli = new mysqli("127.0.0.1", "root", "", "test");

// Check connection
if ($mysqli->connect_errno) {
    die("MySQLi connection failed: " . $mysqli->connect_error . PHP_EOL);
}

// Run a query using mysqli
$mysqli_result = $mysqli->query("SELECT * FROM wptests_users WHERE ID = 1");
$mysqli_row = $mysqli_result->fetch_assoc();

echo PHP_EOL . "MySQLi result:" . PHP_EOL;
var_dump($mysqli_row);

// Close the connection
$mysqli->close();


  `,
});

console.log('Response:', response.text);

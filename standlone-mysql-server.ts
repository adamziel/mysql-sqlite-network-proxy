import fs from 'fs';
import { createServer, Socket } from 'net';
import { createNodeFsMountHandler, getPHPLoaderModule } from '@php-wasm/node';
import { loadPHPRuntime, PHP } from '@php-wasm/universal';
import { bootWordPress } from '@wp-playground/wordpress';

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
// Convert a Node.js socket to a Web ReadableStream of Uint8Array chunks.
function nodeSocketToReadableStream(socket: Socket): ReadableStream<Uint8Array> {
  return new ReadableStream({
    start(controller) {
      socket.on("data", (chunk: Buffer) => {
        controller.enqueue(new Uint8Array(chunk));
      });
      socket.on("end", () => controller.close());
      socket.on("error", (err) => controller.error(err));
    },
    cancel() {
      socket.destroy();
    },
  });
}

// Convert a Node.js socket to a Web WritableStream of Uint8Array chunks.
function nodeSocketToWritableStream(socket: Socket): WritableStream<Uint8Array> {
  return new WritableStream({
    write(chunk) {
      return new Promise<void>((resolve, reject) => {
        socket.write(Buffer.from(chunk), (err) => (err ? reject(err) : resolve()));
      });
    },
    close() {
      socket.end();
    },
    abort(reason) {
      socket.destroy(reason);
    },
  });
}

// A simple query handler that always returns mock data.
const queryHandler = async (query: string): Promise<QueryResult> => {
	return await mysqlToSqliteProxy.runQuery(query);
	console.log("Received query:", query);
	if (!query.toLowerCase().trim().startsWith("select")) {
		return {
			affectedRows: 0,
			insertId: 0,
		};
	}
  return {
    columns: [{ name: "mock", type: 0xfd }],
    rows: [[ "Hello, World!" ]],
  };
};

// Create a server on port 3306.
const server = createServer((socket: Socket) => {
  console.log("New connection from", socket.remoteAddress);
  const wsReadable = nodeSocketToReadableStream(socket);
  const wsWritable = nodeSocketToWritableStream(socket);
  const mysqlConn = new MySQLProtocolConnection(queryHandler);

  // Pipe incoming data into our MySQL protocol handler.
  wsReadable.pipeTo(mysqlConn.writable).catch((err) =>
    console.error("Error piping input:", err)
  );
  // Pipe outgoing data from our MySQL protocol handler back to the socket.
  mysqlConn.readable.pipeTo(wsWritable).catch((err) =>
    console.error("Error piping output:", err)
  );
});

server.listen(3306, () => {
  console.log("MySQL mock server listening on port 3306");
});


export interface ColumnDefinition {
    name: string;
    type: number;
    schema?: string;
    table?: string;
    orgName?: string;
    orgTable?: string;
    flags?: number;
    decimals?: number;
    columnLength?: number;
}

export interface QueryResult {
    columns?: ColumnDefinition[];
    rows?: Iterable<any[]> | AsyncIterable<any[]>;
    affectedRows?: number;
    insertId?: number;
}

export type QueryHandler = (query: string, connection: MySQLProtocolConnection) => QueryResult | Promise<QueryResult>;

export class MySQLProtocolConnection {
    public readonly readable: ReadableStream<Uint8Array>;
    public readonly writable: WritableStream<Uint8Array>;
    public user: string | null = null;
    public database: string | null = null;
    public connectionId: number;
    private _handler: QueryHandler;
    private _outSeq: number = 0;
    private _handshakeDone: boolean = false;
    private _serverVersion: string;
    private _authPlugin: string = "mysql_native_password";
    private _authSalt: Uint8Array;
    private _capabilityFlags: number;
    private _statusFlags: number = 0x0002;  // SERVER_STATUS_AUTOCOMMIT
    private _charset: number = 33;  // utf8_general_ci (collation ID 33)
    private _buffer: Uint8Array | null = null;
    private _partialPayload: Uint8Array | null = null;

    constructor(queryHandler: QueryHandler, serverVersion: string = "5.7.0") {
        this._handler = queryHandler;
        this._serverVersion = serverVersion;
        // Initialize connection ID and auth salt
        this.connectionId = Math.floor(Math.random() * 0xffffff);
        this._authSalt = this._generateAuthSalt();
        // Set server capability flags (support Protocol 41, Secure Connection, Plugin Auth, etc.)
        const CLIENT_LONG_FLAG = 0x00000004;
        const CLIENT_CONNECT_WITH_DB = 0x00000008;
        const CLIENT_PROTOCOL_41 = 0x00000200;
        const CLIENT_SECURE_CONNECTION = 0x00008000;
        const CLIENT_PLUGIN_AUTH = 0x00080000;
        this._capabilityFlags = CLIENT_PROTOCOL_41 | CLIENT_SECURE_CONNECTION | CLIENT_PLUGIN_AUTH | CLIENT_LONG_FLAG | CLIENT_CONNECT_WITH_DB;
        const self = this;
        const transformStream = new TransformStream<Uint8Array, Uint8Array>({
			start(controller) {
                // Send initial handshake packet when stream starts
                const handshakePacket = self._buildHandshakePacket();
                controller.enqueue(handshakePacket);
            },
            async transform(chunk, controller) {
				console.log('transform', chunk);
                // Append incoming data to the internal buffer
                if (!self._buffer || self._buffer.length === 0) {
                    self._buffer = chunk;
                } else {
                    self._buffer = MySQLProtocolConnection._concatBuffers(self._buffer, chunk);
                }
                // Process all complete packets in the buffer
                while (true) {
                    if (!self._buffer || self._buffer.length < 4) {
                        // Not enough data for a full packet header
                        break;
                    }
                    const packetLength = self._buffer[0] | (self._buffer[1] << 8) | (self._buffer[2] << 16);
                    if (self._buffer.length < packetLength + 4) {
                        // Wait for the rest of the packet to arrive
                        break;
                    }
                    const seqId = self._buffer[3];
                    if (packetLength === 0xFFFFFF) {
                        // Maximum packet size, this is a part of a larger packet sequence
                        const partPayload = self._buffer.slice(4, 4 + packetLength);
                        // Remove this part from buffer
                        self._buffer = self._buffer.slice(4 + packetLength);
                        // Accumulate partial payload
                        if (!self._partialPayload) {
                            self._partialPayload = partPayload;
                        } else {
                            self._partialPayload = MySQLProtocolConnection._concatBuffers(self._partialPayload, partPayload);
                        }
                        // Continue parsing for the next part (if present)
                        continue;
                    }
                    // Otherwise, we have a complete packet (or the last part of a large packet)
                    let fullPayload = self._buffer.slice(4, 4 + packetLength);
                    self._buffer = self._buffer.slice(4 + packetLength);
                    if (self._partialPayload) {
                        // Prepend any previously stored partial data
                        fullPayload = MySQLProtocolConnection._concatBuffers(self._partialPayload, fullPayload);
                        self._partialPayload = null;
                    }
                    // Process the complete payload
                    await self._processPacket(fullPayload, seqId, controller);
                    // Loop back to parse any additional packets in the buffer
                }
            },
            flush(controller) {
                // Clean-up if needed when the stream is closed
            }
		});
        this.readable = transformStream.readable;
        this.writable = transformStream.writable;
    }

    // Generate a random 20-byte auth salt (scramble) for authentication
    private _generateAuthSalt(): Uint8Array {
        const salt = new Uint8Array(20);
        if (typeof crypto !== 'undefined' && typeof crypto.getRandomValues === 'function') {
            crypto.getRandomValues(salt);
        } else {
            // Fallback to Math.random if crypto is not available
            for (let i = 0; i < salt.length; i++) {
                salt[i] = Math.floor(Math.random() * 256);
            }
        }
        // Ensure no zero bytes in salt (MySQL expects a null-terminated string)
        for (let i = 0; i < salt.length; i++) {
            if (salt[i] === 0) salt[i] = 1;
        }
        return salt;
    }

    // Build the initial handshake packet to send to the client
    private _buildHandshakePacket(): Uint8Array {
        const protocolVersion = 0x0a;  // MySQL protocol version 10
        const serverVersionBytes = new TextEncoder().encode(this._serverVersion);
        const handshake: number[] = [];
        handshake.push(protocolVersion);
        // Server version string (null-terminated)
        handshake.push(...serverVersionBytes, 0x00);
        // Connection ID (4 bytes, little-endian)
        handshake.push(
            this.connectionId & 0xFF,
            (this.connectionId >> 8) & 0xFF,
            (this.connectionId >> 16) & 0xFF,
            (this.connectionId >> 24) & 0xFF
        );
        // First part of auth-plugin data (8 bytes) + filler 0x00
        const saltPart1 = this._authSalt.slice(0, 8);
        handshake.push(...saltPart1, 0x00);
        // Capability flags (lower 2 bytes)
        const capLower = this._capabilityFlags & 0xFFFF;
        handshake.push(capLower & 0xFF, (capLower >> 8) & 0xFF);
        // Character set (1 byte)
        handshake.push(this._charset);
        // Status flags (2 bytes)
        handshake.push(this._statusFlags & 0xFF, (this._statusFlags >> 8) & 0xFF);
        // Capability flags (upper 2 bytes)
        const capUpper = (this._capabilityFlags >> 16) & 0xFFFF;
        handshake.push(capUpper & 0xFF, (capUpper >> 8) & 0xFF);
        // Auth plugin data length (1 byte) - 21 bytes for mysql_native_password
        handshake.push(21);
        // 10 reserved bytes (all 0x00)
        handshake.push(0,0,0,0,0,0,0,0,0,0);
        // Second part of auth-plugin data (remaining salt bytes) + terminating 0x00
        const saltPart2 = this._authSalt.slice(8);
        handshake.push(...saltPart2, 0x00);
        // Auth plugin name (null-terminated string)
        const pluginNameBytes = new TextEncoder().encode(this._authPlugin);
        handshake.push(...pluginNameBytes, 0x00);
        // Wrap into a packet with header
        const payload = Uint8Array.from(handshake);
        return MySQLProtocolConnection._makePacket(payload, 0);
    }

    // Handle an incoming packet payload from the client
    private async _processPacket(payload: Uint8Array, seqId: number, controller: TransformStreamDefaultController<Uint8Array>): Promise<void> {
        if (!this._handshakeDone) {
            // Process handshake response from client
            await this._handleHandshakeResponse(payload, seqId, controller);
            return;
        }
        if (payload.length === 0) {
            return;  // ignore empty packets
        }
        const command = payload[0];
        // Reset output sequence for a new command (except for COM_QUIT which yields no response)
        if (command !== 0x01) {
            this._outSeq = ((seqId + 1) & 0xFF);
        }
        switch (command) {
            case 0x03:  // COM_QUERY
                {
                    const queryBytes = payload.slice(1);
                    const query = new TextDecoder("utf-8").decode(queryBytes);
                    try {
                        const result = await this._handler(query, this);
                        if (result && (result.columns !== undefined || result.rows !== undefined)) {
                            // Send a result set (columns and rows)
                            await this._sendResultSet(result, controller);
                        } else {
                            // No result set (e.g. an OK result)
                            const affected = result?.affectedRows ?? 0;
                            const insertId = result?.insertId ?? 0;
                            const okPacket = this._buildOkPacket(affected, insertId);
                            this._enqueuePacket(okPacket, controller);
                        }
                    } catch (err: any) {
                        // On error, send an error packet
                        const message = err && err.message ? String(err.message) : "Query execution error";
                        const code = (err && typeof err.code === 'number') ? err.code : 1105;  // ER_UNKNOWN_ERROR
                        const errorPacket = this._buildErrorPacket(code, "HY000", message);
                        this._enqueuePacket(errorPacket, controller);
                    }
                }
                break;
            case 0x02:  // COM_INIT_DB (change default schema)
                {
                    const schemaBytes = payload.slice(1);
                    const schemaName = new TextDecoder("utf-8").decode(schemaBytes);
                    this.database = schemaName;
                    const okPacket = this._buildOkPacket(0, 0);
                    this._enqueuePacket(okPacket, controller);
                }
                break;
            case 0x0e:  // COM_PING
                {
                    const okPacket = this._buildOkPacket(0, 0);
                    this._enqueuePacket(okPacket, controller);
                }
                break;
            case 0x01:  // COM_QUIT
                {
                    // Close connection
					try {
						controller.terminate();
					} catch (err) {
						// @TODO: how to handle termination failure? Maybe just ignore it?
						// console.error("Error terminating controller:", err);
					}
                    return;
                }
            default:
                {
                    // Unknown/unsupported command
                    const errorPacket = this._buildErrorPacket(1047, "08S01", "Unknown command");
                    this._enqueuePacket(errorPacket, controller);
                }
        }
    }

    // Handle the handshake response from the client and send an OK packet
    private async _handleHandshakeResponse(payload: Uint8Array, seqId: number, controller: TransformStreamDefaultController<Uint8Array>): Promise<void> {
        if (payload.length < 34) {
            // Handshake response (Protocol::HandshakeResponse41) must be at least 34 bytes
            controller.terminate();
            return;
        }
        // Read client capability flags (4 bytes little-endian)
        const clientFlags = payload[0] | (payload[1] << 8) | (payload[2] << 16) | (payload[3] << 24);
        // Skip max packet size (4 bytes), character set (1 byte), and reserved 23 bytes
        let offset = 4 + 4 + 1 + 23;
        // Read username (null-terminated)
        let user = "";
        while (offset < payload.length && payload[offset] !== 0x00) {
            user += String.fromCharCode(payload[offset++]);
        }
        offset++;  // skip null terminator
        this.user = user;
        // Read auth-response (length encoded if CLIENT_SECURE_CONNECTION)
        if (clientFlags & 0x00008000) {  // CLIENT_SECURE_CONNECTION
            if (offset < payload.length) {
                const authRespLen = payload[offset++];
                offset += authRespLen;
            }
        } else {
            while (offset < payload.length && payload[offset] !== 0x00) {
                offset++;
            }
            offset++;
        }
        // Read database name (if CLIENT_CONNECT_WITH_DB flag is set)
        let db = "";
        if (clientFlags & 0x00000008) {
            while (offset < payload.length && payload[offset] !== 0x00) {
                db += String.fromCharCode(payload[offset++]);
            }
            offset++;
        }
        if (db) {
            this.database = db;
        }
        // If CLIENT_PLUGIN_AUTH, skip the auth plugin name sent by client
        if (clientFlags & 0x00080000) {
            while (offset < payload.length && payload[offset] !== 0x00) {
                offset++;
            }
            offset++;
        }
        // (Authentication is not validated in this implementation)
        // Send OK packet to acknowledge successful handshake
        const okPayload = this._buildOkPacket(0, 0);
        const okPacket = MySQLProtocolConnection._makePacket(okPayload, (seqId + 1) & 0xFF);
        controller.enqueue(okPacket);
        this._handshakeDone = true;
    }

    // Build a MySQL OK packet payload
    private _buildOkPacket(affectedRows: number, insertId: number, info: string = ""): Uint8Array {
        const bytes: number[] = [];
        bytes.push(0x00);  // OK packet header
        MySQLProtocolConnection._writeLenEncInt(bytes, affectedRows);
        MySQLProtocolConnection._writeLenEncInt(bytes, insertId);
        // Status flags (2 bytes)
        bytes.push(this._statusFlags & 0xFF, (this._statusFlags >> 8) & 0xFF);
        // Number of warnings (2 bytes, always 0 in this implementation)
        bytes.push(0x00, 0x00);
        // Optional human-readable info message
        if (info) {
            const infoBytes = new TextEncoder().encode(info);
            bytes.push(...infoBytes);
        }
        return Uint8Array.from(bytes);
    }

    // Build a MySQL Error packet payload
    private _buildErrorPacket(code: number, sqlState: string, message: string): Uint8Array {
        const bytes: number[] = [];
        bytes.push(0xFF);  // Error packet header
        // Error code (2 bytes)
        bytes.push(code & 0xFF, (code >> 8) & 0xFF);
        bytes.push(0x23);  // SQL state marker '#'
        const state = sqlState.padEnd(5).slice(0, 5);
        for (let i = 0; i < state.length; i++) {
            bytes.push(state.charCodeAt(i));
        }
        const msgBytes = new TextEncoder().encode(message);
        bytes.push(...msgBytes);
        return Uint8Array.from(bytes);
    }

    // Send a result set (columns and rows) as packets to the client
    private async _sendResultSet(result: QueryResult, controller: TransformStreamDefaultController<Uint8Array>) {
        let columns = result.columns;
        const rowsSource = result.rows;
        let firstRow: any = undefined;
        let rowsIter: Iterator<any> | AsyncIterator<any> | null = null;
        let isAsync = false;
        // If columns are not provided, derive columns from the first row of rowsSource
        if (!columns && rowsSource) {
            const asyncIteratorMethod = (rowsSource as AsyncIterable<any>)[Symbol.asyncIterator];
            if (asyncIteratorMethod) {
                isAsync = true;
                rowsIter = asyncIteratorMethod.call(rowsSource);
                const firstResult = await (rowsIter as AsyncIterator<any>).next();
                if (!firstResult.done) {
                    firstRow = firstResult.value;
                }
            } else if ((rowsSource as Iterable<any>)[Symbol.iterator]) {
                rowsIter = (rowsSource as Iterable<any>)[Symbol.iterator]();
                const firstResult = (rowsIter as Iterator<any>).next();
                if (!firstResult.done) {
                    firstRow = firstResult.value;
                }
            }
            if (firstRow !== undefined) {
                if (Array.isArray(firstRow)) {
                    // Derive generic column names if row is array
                    columns = firstRow.map((val: any, idx: number) => ({
                        name: "column" + (idx + 1),
                        type: MySQLProtocolConnection._guessType(val)
                    }));
                } else if (typeof firstRow === "object" && firstRow !== null) {
                    const colNames = Object.keys(firstRow);
                    columns = colNames.map(name => {
                        const val = (firstRow as any)[name];
                        return { name, type: MySQLProtocolConnection._guessType(val) };
                    });
                } else {
                    // Primitive first row (wrap as single column)
                    columns = [{ name: "result", type: MySQLProtocolConnection._guessType(firstRow) }];
                }
            } else {
                columns = columns || [];
            }
        }
        if (!columns) {
            columns = [];
        }
        const columnCount = columns.length;
        if (columnCount === 0) {
            // No columns (e.g., an OK result with no result set)
            const okPacket = this._buildOkPacket(0, 0);
            this._enqueuePacket(okPacket, controller);
            return;
        }
        // Column count packet
        const colCountPayload: number[] = [];
        MySQLProtocolConnection._writeLenEncInt(colCountPayload, columnCount);
        this._enqueuePacket(Uint8Array.from(colCountPayload), controller);
        // Column definition packets for each column
        for (const col of columns) {
            const colDefPayload = this._buildColumnDefinitionPacket(col);
            this._enqueuePacket(colDefPayload, controller);
        }
        // EOF packet after column definitions
        const eofAfterCols = this._buildEOFPacket();
        this._enqueuePacket(eofAfterCols, controller);
        // If we pulled a first row (for deriving columns), send it first
        if (firstRow !== undefined) {
            const firstRowArray = Array.isArray(firstRow)
                ? firstRow
                : (typeof firstRow === "object" ? columns.map(c => (firstRow as any)[c.name]) : [firstRow]);
            const firstRowPacket = this._buildTextRowPacket(firstRowArray);
            this._enqueuePacket(firstRowPacket, controller);
        }
        // Send remaining rows
        if (rowsSource) {
            if (isAsync && rowsIter) {
                while (true) {
                    const res = await (rowsIter as AsyncIterator<any>).next();
                    if (res.done) break;
                    const row = res.value;
                    const rowArray = Array.isArray(row)
                        ? row
                        : (typeof row === "object" && row !== null ? columns.map(c => (row as any)[c.name]) : [row]);
                    const rowPacket = this._buildTextRowPacket(rowArray);
                    this._enqueuePacket(rowPacket, controller);
                }
            } else if (!isAsync && rowsIter) {
                let res = (rowsIter as Iterator<any>).next();
                while (!res.done) {
                    const row = res.value;
                    const rowArray = Array.isArray(row)
                        ? row
                        : (typeof row === "object" && row !== null ? columns.map(c => (row as any)[c.name]) : [row]);
                    const rowPacket = this._buildTextRowPacket(rowArray);
                    this._enqueuePacket(rowPacket, controller);
                    res = (rowsIter as Iterator<any>).next();
                }
            } else if (!isAsync && Array.isArray(rowsSource)) {
                for (const row of rowsSource) {
                    const rowArray = Array.isArray(row)
                        ? row
                        : (typeof row === "object" && row !== null ? columns.map(c => (row as any)[c.name]) : [row]);
                    const rowPacket = this._buildTextRowPacket(rowArray);
                    this._enqueuePacket(rowPacket, controller);
                }
            } else if (!isAsync && rowsSource) {
                for (const row of rowsSource as Iterable<any>) {
                    const rowArray = Array.isArray(row)
                        ? row
                        : (typeof row === "object" && row !== null ? columns.map(c => (row as any)[c.name]) : [row]);
                    const rowPacket = this._buildTextRowPacket(rowArray);
                    this._enqueuePacket(rowPacket, controller);
                }
            }
        }
        // EOF packet after all rows
        const eofAfterRows = this._buildEOFPacket();
        this._enqueuePacket(eofAfterRows, controller);
    }

    // Build a Column Definition packet payload (Protocol::ColumnDefinition41)
    private _buildColumnDefinitionPacket(col: ColumnDefinition): Uint8Array {
        const bytes: number[] = [];
        const encoder = new TextEncoder();
        const catalog = "def";
        const schema = col.schema ?? this.database ?? "";
        const table = col.table ?? "";
        const orgTable = col.orgTable ?? table;
        const name = col.name;
        const orgName = col.orgName ?? name;
        const fixedLenFields = 0x0c;
        const charset = this._charset;
        // Determine column length (if not provided, use default based on type)
        let columnLength: number;
        if (col.columnLength !== undefined) {
            columnLength = col.columnLength;
        } else {
            switch (col.type) {
                case 0x03:  // LONG (INT32)
                    columnLength = 11;
                    break;
                case 0x08:  // LONGLONG (INT64)
                    columnLength = 20;
                    break;
                case 0x04:  // FLOAT
                case 0x05:  // DOUBLE
                    columnLength = 31;
                    break;
                case 0x01:  // TINY (TINYINT/BOOLEAN)
                    columnLength = 4;
                    break;
                case 0x0a:  // DATE
                    columnLength = 10;
                    break;
                case 0x07:  // TIMESTAMP
                case 0x0c:  // DATETIME
                    columnLength = 19;
                    break;
                case 0x06:  // NULL
                    columnLength = 0;
                    break;
                default:
                    columnLength = 256;
                    break;
            }
        }
        const type = col.type;
        const flags = col.flags ?? 0;
        let decimals: number;
        if (col.decimals !== undefined) {
            decimals = col.decimals;
        } else {
            if (type === 0x04 || type === 0x05) {
                decimals = 31;
            } else if (type === 0x03 || type === 0x08 || type === 0x01 || type === 0x02 || type === 0x09) {
                decimals = 0;
            } else {
                decimals = 0;
            }
        }
        // Write length-encoded strings for catalog, schema, table, orgTable, name, orgName
        MySQLProtocolConnection._writeLenEncString(bytes, catalog, encoder);
        MySQLProtocolConnection._writeLenEncString(bytes, schema, encoder);
        MySQLProtocolConnection._writeLenEncString(bytes, table, encoder);
        MySQLProtocolConnection._writeLenEncString(bytes, orgTable, encoder);
        MySQLProtocolConnection._writeLenEncString(bytes, name, encoder);
        MySQLProtocolConnection._writeLenEncString(bytes, orgName, encoder);
        // Fixed-length fields part
        bytes.push(fixedLenFields);
        // character set (2 bytes)
        bytes.push(charset & 0xFF, (charset >> 8) & 0xFF);
        // column length (4 bytes)
        bytes.push(columnLength & 0xFF, (columnLength >> 8) & 0xFF, (columnLength >> 16) & 0xFF, (columnLength >> 24) & 0xFF);
        // column type
        bytes.push(type);
        // flags (2 bytes)
        bytes.push(flags & 0xFF, (flags >> 8) & 0xFF);
        // decimals (1 byte)
        bytes.push(decimals);
        // filler (2 bytes)
        bytes.push(0x00, 0x00);
        return Uint8Array.from(bytes);
    }

    // Build an EOF (end of file) packet payload (used as a marker in text protocol)
    private _buildEOFPacket(): Uint8Array {
        const buf = new Uint8Array(5);
        buf[0] = 0xFE;
        buf[1] = 0x00;
        buf[2] = 0x00;
        // status flags (2 bytes)
        buf[3] = this._statusFlags & 0xFF;
        buf[4] = (this._statusFlags >> 8) & 0xFF;
        return buf;
    }

    // Build a text result row packet payload
    private _buildTextRowPacket(row: any[]): Uint8Array {
        const bytes: number[] = [];
        const encoder = new TextEncoder();
        for (const val of row) {
            if (val === null || val === undefined) {
                bytes.push(0xFB);  // NULL value marker
            } else {
                let strVal: string;
                if (typeof val === "bigint") {
                    strVal = val.toString();
                } else if (val instanceof Date) {
                    // Format date as "YYYY-MM-DD HH:MM:SS"
                    const pad = (n: number) => n.toString().padStart(2, '0');
                    strVal = `${val.getUTCFullYear()}-${pad(val.getUTCMonth()+1)}-${pad(val.getUTCDate())} ` +
                             `${pad(val.getUTCHours())}:${pad(val.getUTCMinutes())}:${pad(val.getUTCSeconds())}`;
                } else {
                    strVal = String(val);
                }
                const strBytes = encoder.encode(strVal);
                MySQLProtocolConnection._writeLenEncInt(bytes, strBytes.length);
                bytes.push(...strBytes);
            }
        }
        return Uint8Array.from(bytes);
    }

    // Enqueue a packet payload to the output, splitting into 16MB packets if necessary
    private _enqueuePacket(payload: Uint8Array, controller: TransformStreamDefaultController<Uint8Array>) {
        const MAX_PACKET_SIZE = 0xFFFFFF;
        let offset = 0;
        while (offset < payload.length) {
            const chunkSize = Math.min(MAX_PACKET_SIZE, payload.length - offset);
            const chunkPayload = payload.slice(offset, offset + chunkSize);
            const packet = MySQLProtocolConnection._makePacket(chunkPayload, this._outSeq);
            controller.enqueue(packet);
            this._outSeq = (this._outSeq + 1) & 0xFF;
            offset += chunkSize;
            // If the payload size is an exact multiple of MAX_PACKET_SIZE, send an empty packet to terminate
            if (chunkSize === MAX_PACKET_SIZE && offset === payload.length) {
                const terminator = MySQLProtocolConnection._makePacket(new Uint8Array(0), this._outSeq);
                controller.enqueue(terminator);
                this._outSeq = (this._outSeq + 1) & 0xFF;
            }
        }
    }

    // Create a packet with a 4-byte header (length and sequence) from a payload
    private static _makePacket(payload: Uint8Array, seq: number): Uint8Array {
        const length = payload.length;
        const packet = new Uint8Array(4 + length);
        packet[0] = length & 0xFF;
        packet[1] = (length >> 8) & 0xFF;
        packet[2] = (length >> 16) & 0xFF;
        packet[3] = seq & 0xFF;
        if (length > 0) {
            packet.set(payload, 4);
        }
        return packet;
    }

    // Concatenate two Uint8Array buffers
    private static _concatBuffers(a: Uint8Array, b: Uint8Array): Uint8Array {
        const result = new Uint8Array(a.length + b.length);
        result.set(a, 0);
        result.set(b, a.length);
        return result;
    }

    // Write a length-encoded integer to an array of bytes
    private static _writeLenEncInt(arr: number[], value: number) {
        if (value < 0xFB) {
            arr.push(value);
        } else if (value < 0x10000) {
            arr.push(0xFC, value & 0xFF, (value >> 8) & 0xFF);
        } else if (value < 0x1000000) {
            arr.push(0xFD, value & 0xFF, (value >> 8) & 0xFF, (value >> 16) & 0xFF);
        } else {
            arr.push(0xFE);
            // Use BigInt to handle up to 64-bit
            let big = BigInt(value) & BigInt("0xFFFFFFFFFFFFFFFF");
            for (let i = 0; i < 8; i++) {
                arr.push(Number(big & BigInt(0xFF)));
                big >>= BigInt(8);
            }
        }
    }

    // Write a length-encoded string to an array of bytes
    private static _writeLenEncString(arr: number[], str: string, encoder: TextEncoder) {
        const strBytes = encoder.encode(str);
        MySQLProtocolConnection._writeLenEncInt(arr, strBytes.length);
        arr.push(...strBytes);
    }

    // Guess the MySQL column type from a JavaScript value
    private static _guessType(val: any): number {
        if (val === null || val === undefined) {
            return 0x06;  // NULL
        }
        if (typeof val === "number") {
            if (Number.isInteger(val)) {
                // Use INT for 32-bit safe integers, otherwise BIGINT
                return (val >= -0x80000000 && val < 0x80000000) ? 0x03 : 0x08;
            }
            return 0x05;  // DOUBLE for non-integers
        }
        if (typeof val === "bigint") {
            return 0x08;  // LONGLONG (BIGINT)
        }
        if (typeof val === "boolean") {
            return 0x01;  // TINY (treat boolean as tinyint)
        }
        if (val instanceof Date) {
            return 0x0c;  // DATETIME
        }
        // Default to VAR_STRING for string or other types
        return 0xFD;
    }
}

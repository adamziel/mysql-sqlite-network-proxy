<?php

class MySQLServerException extends Exception {
}

interface MySQLServerQueryResult {
	public function toPackets(): string;
}

interface MySQLQueryHandler {
	public function handleQuery(string $query): MySQLServerQueryResult;
}

class SelectQueryResult implements MySQLServerQueryResult {
    public array $columns;  // Each column: ['name' => string, 'type' => int, 'length' => int, 'flags' => int, 'decimals' => int]
    public array $rows;     // Array of rows, each an array of values (strings, numbers, or null)

    public function __construct(array $columns = [], array $rows = []) {
        $this->columns = $columns;
        $this->rows = $rows;
    }

	public function toPackets(): string {
		return MySQLProtocol::buildResultSetPackets($this);
	}
}

class OkayPacketResult implements MySQLServerQueryResult {
	public int $affectedRows;
	public int $lastInsertId;

	public function __construct(int $affectedRows, int $lastInsertId) {
		$this->affectedRows = $affectedRows;
		$this->lastInsertId = $lastInsertId;
	}

	public function toPackets(): string {
		$ok_packet = MySQLProtocol::buildOkPacket($this->affectedRows, $this->lastInsertId);
		return MySQLProtocol::encodeInt24(strlen($ok_packet)) . MySQLProtocol::encodeInt8(1) . $ok_packet;
	}
}

class ErrorQueryResult implements MySQLServerQueryResult {
	public string $code;
	public string $sqlState;
	public string $message;

	public function __construct(string $message = "Syntax error or unsupported query", string $sqlState = "42000", int $code = 0x04A7) {
		$this->code = $code;
		$this->sqlState = $sqlState;
		$this->message = $message;
	}

	public function toPackets(): string {
		$err_packet = MySQLProtocol::buildErrPacket($this->code, $this->sqlState, $this->message);
		return MySQLProtocol::encodeInt24(strlen($err_packet)) . MySQLProtocol::encodeInt8(1) . $err_packet;
	}
}

class MySQLProtocol {
    // MySQL client/server capability flags (partial list)
    const CLIENT_LONG_FLAG            = 0x00000004;  // Supports longer flags
    const CLIENT_CONNECT_WITH_DB      = 0x00000008;
    const CLIENT_PROTOCOL_41          = 0x00000200;
    const CLIENT_SECURE_CONNECTION    = 0x00008000;
    const CLIENT_MULTI_STATEMENTS     = 0x00010000;
    const CLIENT_MULTI_RESULTS        = 0x00020000;
    const CLIENT_PS_MULTI_RESULTS     = 0x00040000;
    const CLIENT_PLUGIN_AUTH          = 0x00080000;
    const CLIENT_CONNECT_ATTRS        = 0x00100000;
    const CLIENT_PLUGIN_AUTH_LENENC_CLIENT_DATA = 0x00200000;
    const CLIENT_DEPRECATE_EOF        = 0x01000000;

    // MySQL status flags
    const SERVER_STATUS_AUTOCOMMIT    = 0x0002;

	/**
	 * MySQL command types
	 * 
	 * @see https://dev.mysql.com/doc/dev/mysql-server/8.4.3/page_protocol_command_phase.html
	 */
	/** Tells the server that the client wants it to close the connection. */
	const COM_QUIT                    = 0x01;
	/** Tells the server to execute a query. */
    const COM_QUERY                   = 0x03;
	/** Check if the server is alive. */
    const COM_PING                    = 0x0E;
	/** Tells the server to send the binlog dump. */
    const COM_BINLOG_DUMP             = 0x12;
	/** Tells the server to register a slave. */
    const COM_REGISTER_SLAVE          = 0x15;
	/**
	 * The server returns a COM_STMT_PREPARE Response which contains a statement-id which is ised to identify the prepared statement.
	 */
    const COM_STMT_PREPARE            = 0x16;
	/**
	 * Asks the server to execute a prepared statement as identified by statement_id.
	 */
    const COM_STMT_EXECUTE            = 0x17;
    const COM_STMT_CLOSE              = 0x19;
	/**
	 * COM_STMT_RESET resets the data of a prepared statement which was accumulated with
	 * COM_STMT_SEND_LONG_DATA commands and closes the cursor if it was opened with
	 * COM_STMT_EXECUTE.
	 * 
	 * The server will send a OK_Packet if the statement could be reset, a ERR_Packet if not.
	 * 
	 * @see https://dev.mysql.com/doc/dev/mysql-server/8.4.3/page_protocol_com_stmt_reset.html
	 */
    const COM_STMT_RESET              = 0x1A;

    // Special packet markers
    const OK_PACKET    = 0x00;
    const EOF_PACKET   = 0xfe;
    const ERR_PACKET   = 0xff;
    const AUTH_MORE_DATA = 0x01;  // followed by 1 byte (caching_sha2_password specific)

    // Auth specific markers for caching_sha2_password
    const CACHING_SHA2_FAST_AUTH    = 3;
    const CACHING_SHA2_FULL_AUTH    = 4;
    const AUTH_PLUGIN_NAME          = 'caching_sha2_password';

    // Character set and collation constants (using utf8mb4 general collation)
    const CHARSET_UTF8MB4 = 0xff;  // Collation ID 255 (utf8mb4_0900_ai_ci)

    // Max packet length constant
    const MAX_PACKET_LENGTH = 0x00ffffff;

    // Helper: Packets assembly and parsing
    public static function encodeInt8(int $val): string {
        return chr($val & 0xff);
    }
    public static function encodeInt16(int $val): string {
        return pack('v', $val & 0xffff);
    }
    public static function encodeInt24(int $val): string {
        // 3-byte little-endian integer
        return substr(pack('V', $val & 0xffffff), 0, 3);
    }
    public static function encodeInt32(int $val): string {
        return pack('V', $val);
    }
    public static function encodeLengthEncodedInt(int $val): string {
        // Encodes an integer in MySQL's length-encoded format
        if ($val < 0xfb) {
            return chr($val);
        } elseif ($val <= 0xffff) {
            return "\xfc" . self::encodeInt16($val);
        } elseif ($val <= 0xffffff) {
            return "\xfd" . self::encodeInt24($val);
        } else {
            return "\xfe" . pack('P', $val); // 8-byte little-endian for 64-bit
        }
    }
    public static function encodeLengthEncodedString(string $str): string {
        return self::encodeLengthEncodedInt(strlen($str)) . $str;
    }

    // Hashing for caching_sha2_password (fast auth algorithm)
    public static function sha256Hash(string $password, string $salt): string {
        $stage1 = hash('sha256', $password, true);
        $stage2 = hash('sha256', $stage1, true);
        $scramble = hash('sha256', $stage2 . substr($salt, 0, 20), true);
        // XOR stage1 and scramble to get token
        return $stage1 ^ $scramble;
    }

    // Build initial handshake packet (server greeting)
    public static function buildHandshakePacket(int $connId, string &$authPluginData): string {
        $protocol_version = 0x0a;                     // Handshake protocol version (10)
        $server_version   = "5.7.30-php-mysql-server"; // Fake server version
        // Generate random auth plugin data (20-byte salt)
        $salt1 = random_bytes(8);
        $salt2 = random_bytes(12); // total salt length = 8+12 = 20 bytes (with filler)
        $authPluginData = $salt1 . $salt2;
        // Lower 2 bytes of capability flags
        $capFlagsLower = (
            self::CLIENT_PROTOCOL_41 |
            self::CLIENT_SECURE_CONNECTION |
            self::CLIENT_PLUGIN_AUTH |
            self::CLIENT_PLUGIN_AUTH_LENENC_CLIENT_DATA
        ) & 0xffff;
        // Upper 2 bytes of capability flags
        $capFlagsUpper = (
            self::CLIENT_PROTOCOL_41 |
            self::CLIENT_SECURE_CONNECTION |
            self::CLIENT_PLUGIN_AUTH |
            self::CLIENT_PLUGIN_AUTH_LENENC_CLIENT_DATA
        ) >> 16;
        $charset     = self::CHARSET_UTF8MB4;
        $statusFlags = self::SERVER_STATUS_AUTOCOMMIT;

        // Assemble handshake packet payload
        $payload  = chr($protocol_version);
        $payload .= $server_version . "\0";
        $payload .= self::encodeInt32($connId);
        $payload .= $salt1;
        $payload .= "\0";  // filler byte
        $payload .= self::encodeInt16($capFlagsLower);
        $payload .= chr($charset);
        $payload .= self::encodeInt16($statusFlags);
        $payload .= self::encodeInt16($capFlagsUpper);
        $payload .= chr(strlen($authPluginData) + 1);  // auth plugin data length (salt + \0)
        $payload .= str_repeat("\0", 10);              // 10-byte reserved filler
        $payload .= $salt2;
        $payload .= "\0";  // terminating NUL for auth-plugin-data-part-2
        $payload .= self::AUTH_PLUGIN_NAME . "\0";
        return $payload;
    }

    // Build OK packet (after successful authentication or query execution)
    public static function buildOkPacket(int $affectedRows = 0, int $lastInsertId = 0): string {
        $payload  = chr(self::OK_PACKET);
        $payload .= self::encodeLengthEncodedInt($affectedRows);
        $payload .= self::encodeLengthEncodedInt($lastInsertId);
        $payload .= self::encodeInt16(self::SERVER_STATUS_AUTOCOMMIT); // server status
        $payload .= self::encodeInt16(0);  // no warning count
        // No human-readable message for simplicity
        return $payload;
    }

    // Build ERR packet (for errors)
    public static function buildErrPacket(int $errorCode, string $sqlState, string $message): string {
        $payload  = chr(self::ERR_PACKET);
        $payload .= self::encodeInt16($errorCode);
        $payload .= "#" . strtoupper($sqlState);
        $payload .= $message;
        return $payload;
    }

    // Build Result Set packets from a SelectQueryResult (column count, column definitions, rows, EOF)
    public static function buildResultSetPackets(SelectQueryResult $result): string {
        $sequenceId = 1;  // Sequence starts at 1 for resultset (after COM_QUERY)
        $packetStream = '';

        // 1. Column count packet (length-encoded integer for number of columns)
        $colCount = count($result->columns);
        $colCountPayload = self::encodeLengthEncodedInt($colCount);
        $packetStream .= self::wrapPacket($colCountPayload, $sequenceId++);

        // 2. Column definition packets for each column
        foreach ($result->columns as $col) {
            // Protocol::ColumnDefinition41 format:]
            $colPayload  = self::encodeLengthEncodedString($col['catalog'] ?? 'sqlite');
            $colPayload .= self::encodeLengthEncodedString($col['schema'] ?? '');

			// Table alias
            $colPayload .= self::encodeLengthEncodedString($col['table'] ?? '');

			// Original table name
            $colPayload .= self::encodeLengthEncodedString($col['orgTable'] ?? '');

			// Column alias
            $colPayload .= self::encodeLengthEncodedString($col['name']);

			// Original column name
            $colPayload .= self::encodeLengthEncodedString($col['orgName'] ?? $col['name']);

			// Length of the remaining fixed fields. @TODO: What does that mean?
            $colPayload .= self::encodeLengthEncodedInt($col['fixedLen'] ?? 0x0c);
            $colPayload .= self::encodeInt16($col['charset'] ?? MySQLProtocol::CHARSET_UTF8MB4);
            $colPayload .= self::encodeInt32($col['length']);
            $colPayload .= self::encodeInt8($col['type']);
            $colPayload .= self::encodeInt16($col['flags']);
            $colPayload .= self::encodeInt8($col['decimals']);
            $colPayload .= "\x00";  // filler (1 byte, reserved)

            $packetStream .= self::wrapPacket($colPayload, $sequenceId++);
        }
        // 3. EOF packet to mark end of column definitions (if not using CLIENT_DEPRECATE_EOF)
        $eofPayload = chr(self::EOF_PACKET) . self::encodeInt16(0) . self::encodeInt16(0);
        $packetStream .= self::wrapPacket($eofPayload, $sequenceId++);

        // 4. Row data packets (each row is a series of length-encoded values)
        foreach ($result->rows as $row) {
            $rowPayload = "";
            foreach ($row as $val) {
                if ($val === null) {
                    // NULL is represented by 0xfb (NULL_VALUE)
                    $rowPayload .= "\xfb";
                } else {
                    $valStr = (string)$val;
                    $rowPayload .= self::encodeLengthEncodedString($valStr);
                }
            }
            $packetStream .= self::wrapPacket($rowPayload, $sequenceId++);
        }

        // 5. EOF packet to mark end of data rows (if not using CLIENT_DEPRECATE_EOF)
        $eofPayload2 = chr(self::EOF_PACKET) . self::encodeInt16(0) . self::encodeInt16(0);
        $packetStream .= self::wrapPacket($eofPayload2, $sequenceId++);

        return $packetStream;
    }

    // Helper to wrap a payload into a packet with length and sequence id
    public static function wrapPacket(string $payload, int $sequenceId): string {
        $length = strlen($payload);
        $header = self::encodeInt24($length) . self::encodeInt8($sequenceId);
        return $header . $payload;
    }
}

class IncompleteInputException extends MySQLServerException {
    public function __construct(string $message = "Incomplete input data, more bytes needed") {
        parent::__construct($message);
    }
}

class MySQLGateway {
    private $query_handler;
    private $connection_id;
    private $auth_plugin_data;
    private $sequence_id;
    private $authenticated = false;
    private $buffer = '';

    public function __construct(MySQLQueryHandler $query_handler) {
        $this->query_handler = $query_handler;
        $this->connection_id = random_int(1, 1000);
        $this->auth_plugin_data = "";
        $this->sequence_id = 0;
    }

    /**
     * Get the initial handshake packet to send to the client
     * 
     * @return string Binary packet data to send to client
     */
    public function getInitialHandshake(): string {
        $handshakePayload = MySQLProtocol::buildHandshakePacket($this->connection_id, $this->auth_plugin_data);
        return MySQLProtocol::encodeInt24(strlen($handshakePayload)) . 
               MySQLProtocol::encodeInt8($this->sequence_id++) . 
               $handshakePayload;
    }

    /**
     * Process bytes received from the client
     * 
     * @param string $data Binary data received from client
     * @return string|null Response to send back to client, or null if no response needed
     * @throws IncompleteInputException When more data is needed to complete a packet
     */
    public function receiveBytes(string $data): ?string {
        // Append new data to existing buffer
        $this->buffer .= $data;
        
        // Check if we have enough data for a header
        if (strlen($this->buffer) < 4) {
            throw new IncompleteInputException("Incomplete packet header, need more bytes");
        }

        // Parse packet header
        $packetLength = unpack('V', substr($this->buffer, 0, 3) . "\x00")[1];
        $receivedSequenceId = ord($this->buffer[3]);
        
        // Check if we have the complete packet
        $totalPacketLength = 4 + $packetLength;
        if (strlen($this->buffer) < $totalPacketLength) {
            throw new IncompleteInputException(
                "Incomplete packet payload, have " . strlen($this->buffer) . 
                " bytes, need " . $totalPacketLength . " bytes"
            );
        }

        // Extract the complete packet
        $packet = substr($this->buffer, 0, $totalPacketLength);
        
        // Remove the processed packet from the buffer
        $this->buffer = substr($this->buffer, $totalPacketLength);
        
        // Process the packet
        $payload = substr($packet, 4, $packetLength);
        
        // If not authenticated yet, process authentication
        if (!$this->authenticated) {
            return $this->processAuthentication($payload);
        }
        
        // Otherwise, process as a command
        $command = ord($payload[0]);
        if ($command === MySQLProtocol::COM_QUERY) {
            $query = substr($payload, 1);
            return $this->processQuery($query);
        } else {
            // Unsupported command
            $errPacket = MySQLProtocol::buildErrPacket(0x04D2, "HY000", "Unsupported command");
            return MySQLProtocol::encodeInt24(strlen($errPacket)) . 
                   MySQLProtocol::encodeInt8(1) . 
                   $errPacket;
        }
    }

    /**
     * Process authentication packet from client
     * 
     * @param string $payload Authentication packet payload
     * @return string Response packet to send back
     */
    private function processAuthentication(string $payload): string {
        $offset = 0;
        $payloadLength = strlen($payload);

        $capabilityFlags = $this->readUnsignedIntLittleEndian($payload, $offset, 4);
        $offset += 4;

        $clientMaxPacketSize = $this->readUnsignedIntLittleEndian($payload, $offset, 4);
        $offset += 4;

        $clientCharacterSet = 0;
        if ($offset < $payloadLength) {
            $clientCharacterSet = ord($payload[$offset]);
        }
        $offset += 1;

        // Skip reserved bytes (always zero)
        $offset = min($payloadLength, $offset + 23);

        $username = $this->readNullTerminatedString($payload, $offset);

        $authResponse = '';
        if ($capabilityFlags & MySQLProtocol::CLIENT_PLUGIN_AUTH_LENENC_CLIENT_DATA) {
            $authResponseLength = $this->readLengthEncodedInt($payload, $offset);
            $authResponse = substr($payload, $offset, $authResponseLength);
            $offset = min($payloadLength, $offset + $authResponseLength);
        } elseif ($capabilityFlags & MySQLProtocol::CLIENT_SECURE_CONNECTION) {
            $authResponseLength = 0;
            if ($offset < $payloadLength) {
                $authResponseLength = ord($payload[$offset]);
            }
            $offset += 1;
            $authResponse = substr($payload, $offset, $authResponseLength);
            $offset = min($payloadLength, $offset + $authResponseLength);
        } else {
            $authResponse = $this->readNullTerminatedString($payload, $offset);
        }

        $database = '';
        if ($capabilityFlags & MySQLProtocol::CLIENT_CONNECT_WITH_DB) {
            $database = $this->readNullTerminatedString($payload, $offset);
        }

        $authPluginName = '';
        if ($capabilityFlags & MySQLProtocol::CLIENT_PLUGIN_AUTH) {
            $authPluginName = $this->readNullTerminatedString($payload, $offset);
        }

        if ($capabilityFlags & MySQLProtocol::CLIENT_CONNECT_ATTRS) {
            $attrsLength = $this->readLengthEncodedInt($payload, $offset);
            $offset = min($payloadLength, $offset + $attrsLength);
        }

        $this->authenticated = true;
        $this->sequence_id = 2;

        $responsePackets = '';

        if ($authPluginName === MySQLProtocol::AUTH_PLUGIN_NAME) {
            $fastAuthPayload = chr(MySQLProtocol::AUTH_MORE_DATA) . chr(MySQLProtocol::CACHING_SHA2_FAST_AUTH);
            $responsePackets .= MySQLProtocol::encodeInt24(strlen($fastAuthPayload));
            $responsePackets .= MySQLProtocol::encodeInt8($this->sequence_id++);
            $responsePackets .= $fastAuthPayload;
        }

        $okPacket = MySQLProtocol::buildOkPacket();
        $responsePackets .= MySQLProtocol::encodeInt24(strlen($okPacket));
        $responsePackets .= MySQLProtocol::encodeInt8($this->sequence_id++);
        $responsePackets .= $okPacket;

        return $responsePackets;
    }

    private function readUnsignedIntLittleEndian(string $payload, int $offset, int $length): int {
        $slice = substr($payload, $offset, $length);
        if ($slice === '' || $length <= 0) {
            return 0;
        }

        switch ($length) {
            case 1:
                return ord($slice[0]);
            case 2:
                $padded = str_pad($slice, 2, "\x00", STR_PAD_RIGHT);
                $unpacked = unpack('v', $padded);
                return $unpacked[1] ?? 0;
            case 3:
            case 4:
            default:
                $padded = str_pad($slice, 4, "\x00", STR_PAD_RIGHT);
                $unpacked = unpack('V', $padded);
                return $unpacked[1] ?? 0;
        }
    }

    private function readNullTerminatedString(string $payload, int &$offset): string {
        $nullPosition = strpos($payload, "\0", $offset);
        if ($nullPosition === false) {
            $result = substr($payload, $offset);
            $offset = strlen($payload);
            return $result;
        }

        $result = substr($payload, $offset, $nullPosition - $offset);
        $offset = $nullPosition + 1;
        return $result;
    }

    private function readLengthEncodedInt(string $payload, int &$offset): int {
        if ($offset >= strlen($payload)) {
            return 0;
        }

        $first = ord($payload[$offset]);
        $offset += 1;

        if ($first < 0xfb) {
            return $first;
        }

        if ($first === 0xfb) {
            return 0;
        }

        if ($first === 0xfc) {
            $value = $this->readUnsignedIntLittleEndian($payload, $offset, 2);
            $offset += 2;
            return $value;
        }

        if ($first === 0xfd) {
            $value = $this->readUnsignedIntLittleEndian($payload, $offset, 3);
            $offset += 3;
            return $value;
        }

        // 0xfe indicates an 8-byte integer
        $value = 0;
        $slice = substr($payload, $offset, 8);
        if ($slice !== '') {
            $slice = str_pad($slice, 8, "\x00");
            $value = unpack('P', $slice)[1];
        }
        $offset += 8;
        return (int) $value;
    }

    /**
     * Process a query from the client
     * 
     * @param string $query SQL query to process
     * @return string Response packet to send back
     */
    private function processQuery(string $query): string {
        $query = trim($query);
        
        try {
            $result = $this->query_handler->handleQuery($query);
            return $result->toPackets();
        } catch (MySQLServerException $e) {
            $errPacket = MySQLProtocol::buildErrPacket(0x04A7, "42000", "Syntax error or unsupported query: " . $e->getMessage());
            return MySQLProtocol::encodeInt24(strlen($errPacket)) . 
                   MySQLProtocol::encodeInt8(1) . 
                   $errPacket;
        }
    }

    /**
     * Reset the server state for a new connection
     */
    public function reset(): void {
        $this->connection_id = random_int(1, 1000);
        $this->auth_plugin_data = "";
        $this->sequence_id = 0;
        $this->authenticated = false;
        $this->buffer = '';
    }
    
    /**
     * Check if there's any buffered data that hasn't been processed yet
     * 
     * @return bool True if there's data in the buffer
     */
    public function hasBufferedData(): bool {
        return !empty($this->buffer);
    }
    
    /**
     * Get the number of bytes currently in the buffer
     * 
     * @return int Number of bytes in buffer
     */
    public function getBufferSize(): int {
        return strlen($this->buffer);
    }
}

class SingleUseMySQLSocketServer {
    private $server;
    private $socket;
    private $port;

    public function __construct(MySQLQueryHandler $query_handler, $options = []) {
        $this->server = new MySQLGateway($query_handler);
        $this->port = $options['port'] ?? 3306;
    }

    public function start() {
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_bind($this->socket, '0.0.0.0', $this->port);
        socket_listen($this->socket);
        echo "MySQL PHP Server listening on port {$this->port}...\n";

        // Accept a single client for simplicity
        $client = socket_accept($this->socket);
        if (!$client) {
            exit("Failed to accept connection\n");
        }
        $this->handleClient($client);
        socket_close($client);
        socket_close($this->socket);
    }

    private function handleClient($client) {
        // Send initial handshake
        $handshake = $this->server->getInitialHandshake();
        socket_write($client, $handshake);

        while (true) {
            // Read available data (up to 4096 bytes at a time)
            $data = @socket_read($client, 4096);
            if ($data === false || $data === '') {
                break;  // connection closed
            }
            
            try {
                // Process the data
                $response = $this->server->receiveBytes($data);
                if ($response) {
                    socket_write($client, $response);
                }
                
                // If there's still data in the buffer, process it immediately
                while ($this->server->hasBufferedData()) {
                    try {
                        // Try to process more complete packets from the buffer
                        $response = $this->server->receiveBytes('');
                        if ($response) {
                            socket_write($client, $response);
                        }
                    } catch (IncompleteInputException $e) {
                        // Not enough data to complete another packet, wait for more
                        break;
                    }
                }
            } catch (IncompleteInputException $e) {
                // Not enough data yet, continue reading
                continue;
            }
        }
        
        echo "Client disconnected, terminating the server.\n";
        $this->server->reset();
    }
}

if(!function_exists('post_message_to_js')) {
	function post_message_to_js(string $message) {
		echo 'The "post_message_to_js" function is only available in WordPress Playground but you are running it in a standalone PHP environment.' . PHP_EOL;
		echo 'The message was: ' . $message . PHP_EOL;
	}
}

class MySQLSocketServer {
    private $query_handler;
    private $socket;
    private $port;
    private $clients = [];
    private $clientServers = [];

    public function __construct(MySQLQueryHandler $query_handler, $options = []) {
        $this->query_handler = $query_handler;
        $this->port = $options['port'] ?? 3306;
    }

    public function start() {
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_bind($this->socket, '0.0.0.0', $this->port);
        socket_listen($this->socket);
        echo "MySQL PHP Server listening on port {$this->port}...\n";
        while (true) {
            // Prepare arrays for socket_select()
            $read = array_merge([$this->socket], $this->clients);
            $write = null;
            $except = null;

            // Wait for activity on any socket
			$select_result = socket_select($read, $write, $except, null);
			if($select_result === false || $select_result <= 0) {
				continue;
			}

			// Check if there's a new connection
			if (in_array($this->socket, $read)) {
				$client = socket_accept($this->socket);
				if ($client) {
					echo "New client connected.\n";
					$this->clients[] = $client;
					$clientId = spl_object_id($client);
					$this->clientServers[$clientId] = new MySQLGateway($this->query_handler);
					
					// Send initial handshake
                    echo "Pre handshake\n";
					$handshake = $this->clientServers[$clientId]->getInitialHandshake();
                    echo "Post handshake\n";
					socket_write($client, $handshake);
				}
				// Remove server socket from read array
				unset($read[array_search($this->socket, $read)]);
			}

			// Handle client activity
            echo "Waiting for client activity\n";
			foreach ($read as $client) {
                echo "calling socket_read\n";
				$data = @socket_read($client, 4096);
                echo "socket_read returned\n";
                $display = '';
                for ($i = 0; $i < strlen($data); $i++) {
                    $byte = ord($data[$i]);
                    if ($byte >= 32 && $byte <= 126) {
                        // Printable ASCII character
                        $display .= $data[$i];
                    } else {
                        // Non-printable, show as hex
                        $display .= sprintf('%02x ', $byte);
                    }
                }
                echo rtrim($display) . "\n";
                
				if ($data === false || $data === '') {
					// Client disconnected
					echo "Client disconnected.\n";
					$clientId = spl_object_id($client);
					$this->clientServers[$clientId]->reset();
					unset($this->clientServers[$clientId]);
					socket_close($client);
					unset($this->clients[array_search($client, $this->clients)]);
					continue;
				}

				try {
					// Process the data
					$clientId = spl_object_id($client);
                    echo "Receiving bytes\n";
					$response = $this->clientServers[$clientId]->receiveBytes($data);
					if ($response) {
						echo "Writing response\n";
						echo $response;
						socket_write($client, $response);
					}
                    echo "Response written\n";

					// Process any buffered data
					while ($this->clientServers[$clientId]->hasBufferedData()) {
                        echo "Processing buffered data\n";
						try {
							$response = $this->clientServers[$clientId]->receiveBytes('');
							if ($response) {
								socket_write($client, $response);
							}
						} catch (IncompleteInputException $e) {
							break;
						}
					}
                    echo "After the while loop\n";
				} catch (IncompleteInputException $e) {
                    echo "Incomplete input exception\n";
					continue;
				}
			}
            echo "restarting the while() loop!\n";
        }
    }
}


class MySQLPlaygroundYieldServer {
    private $query_handler;
    private $clients = [];
    private $clientServers = [];
    private $port;

    public function __construct(MySQLQueryHandler $query_handler, $options = []) {
        $this->query_handler = $query_handler;
        $this->port = $options['port'] ?? 3306;
    }

    public function start() {
        echo "MySQL PHP Server listening via message passing on port {$this->port}...\n";

        // Main event loop
        while (true) {
            // Wait for a message from JS
            $message = post_message_to_js(json_encode([
				'type' => 'ready_for_event'
			]));

            $command = json_decode($message, true);
			var_dump('decoded event', $command);
            if (!$command || !isset($command['type'])) {
                continue;
            }
            
            switch ($command['type']) {
                case 'new_connection':
                    $this->handleNewConnection($command['clientId']);
                    break;
                    
                case 'data_received':
                    $this->handleDataReceived($command['clientId'], $command['data']);
                    break;
                    
                case 'client_disconnected':
                    $this->handleClientDisconnected($command['clientId']);
                    break;
            }
        }
    }
    
    private function handleNewConnection($clientId) {
        echo "New client connected (ID: $clientId).\n";
        $this->clients[] = $clientId;
        $this->clientServers[$clientId] = new MySQLGateway($this->query_handler);
        
        // Send initial handshake
        $handshake = $this->clientServers[$clientId]->getInitialHandshake();
		$this->sendResponse($clientId, $handshake);
    }
    
    private function handleDataReceived($clientId, $encodedData) {
        if (!isset($this->clientServers[$clientId])) {
			throw new IncompleteInputException('No client server found');
            return;
        }
        
        $data = base64_decode($encodedData);
        
        try {
            // Process the data
            $response = $this->clientServers[$clientId]->receiveBytes($data);
            if ($response) {
                $this->sendResponse($clientId, $response);
            } else {
				throw new IncompleteInputException('No response from client');
            }
            
            // Process any buffered data
            while ($this->clientServers[$clientId]->hasBufferedData()) {
                try {
                    $response = $this->clientServers[$clientId]->receiveBytes('');
                    if ($response) {
                        $this->sendResponse($clientId, $response);
                    }
                } catch (IncompleteInputException $e) {
					throw $e;
                    break;
                }
            }
        } catch (IncompleteInputException $e) {
            // Not enough data yet, wait for mo
			throw $e;
        }
    }
    
    private function handleClientDisconnected($clientId) {
        echo "Client disconnected (ID: $clientId).\n";
        if (isset($this->clientServers[$clientId])) {
            $this->clientServers[$clientId]->reset();
            unset($this->clientServers[$clientId]);
        }
        
        $index = array_search($clientId, $this->clients);
        if ($index !== false) {
            unset($this->clients[$index]);
        }
    }
    
    private function sendResponse($clientId, $data) {
		var_dump('sending response');
        $response = json_encode([
            'type' => 'response_from_php',
            'clientId' => $clientId,
            'data' => base64_encode($data)
        ]);
        post_message_to_js($response);
    }
}

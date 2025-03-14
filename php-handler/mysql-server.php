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

    // MySQL command types
    const COM_QUERY                   = 0x03;

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
            // Protocol::ColumnDefinition41 format:
            $catalog     = "def";
            $schema      = "";                  // no database schema for custom handler
            $table       = "";                  // no table name (not relevant for custom result)
            $orgTable    = "";
            $name        = $col['name'];        // column alias
            $orgName     = $col['name'];        // original column name
            $fixedLen    = 0x0c;                // length of the remaining fixed fields
            $charset     = self::CHARSET_UTF8MB4;
            $columnLen   = $col['length'];
            $type        = $col['type'];
            $flags       = $col['flags'];
            $decimals    = $col['decimals'];

            $colPayload  = self::encodeLengthEncodedString($catalog);
            $colPayload .= self::encodeLengthEncodedString($schema);
            $colPayload .= self::encodeLengthEncodedString($table);
            $colPayload .= self::encodeLengthEncodedString($orgTable);
            $colPayload .= self::encodeLengthEncodedString($name);
            $colPayload .= self::encodeLengthEncodedString($orgName);
            $colPayload .= self::encodeLengthEncodedInt($fixedLen);
            $colPayload .= self::encodeInt16($charset);
            $colPayload .= self::encodeInt32($columnLen);
            $colPayload .= self::encodeInt8($type);
            $colPayload .= self::encodeInt16($flags);
            $colPayload .= self::encodeInt8($decimals);
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
        // For simplicity, we're auto-accepting all auth attempts
        // In a real implementation, you would parse the handshake response
        // and verify username/password
        
        $this->authenticated = true;
        $this->sequence_id = 2;  // sequence continues: handshake was seq 0, auth response seq 1
        
        $okPacket = MySQLProtocol::buildOkPacket();
        return MySQLProtocol::encodeInt24(strlen($okPacket)) . 
               MySQLProtocol::encodeInt8($this->sequence_id++) . 
               $okPacket;
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

// Example adapter that uses the StreamableMySQLServer with sockets
class MySQLSocketServer {
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
        
        echo "Client disconnected.\n";
        $this->server->reset();
    }
}



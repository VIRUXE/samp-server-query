<?php
/* 
	04/06/2024 - https://github.com/VIRUXE

	Read more on SA-MP's Query mechanism: https://sampwiki.blast.hk/wiki/Query_Mechanism
*/

enum Opcode: string {
	case Info            = 'i';
	case Rules           = 'r';
	case Players         = 'c';
	case DetailedPlayers = 'd';
	case Ping            = 'p';
}

class SampQuery {
	private $socket;
	private $packet;

	public function __construct(
		public readonly string $host,
		public readonly int $port = 7777,
		int $timeout = 1000
	) {
		if (!filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) throw new InvalidArgumentException("Invalid host: $host");
		
		if ($port < 1 || $port > 65535) throw new InvalidArgumentException("Invalid port: $port");
		
		$this->socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
		if ($this->socket === false) throw new Exception('Failed to create socket: ' . socket_strerror(socket_last_error()));

		 // Breakup the ip in parts for the packet "signature"
		$hostParts = explode('.', gethostbyname($host)); // Try to resolve to an ip, in case a hostname was passed
		if (count($hostParts) !== 4) throw new Exception("Invalid IP address after DNS resolution: $host");

		// Make up the packet's "signature"
		$this->packet = 'SAMP' .
			chr($hostParts[0]) .
			chr($hostParts[1]) .
			chr($hostParts[2]) .
			chr($hostParts[3]) .
			chr($port & 0xFF) .
			chr($port >> 8 & 0xFF);

		// Set the timeout we want
		socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => $timeout / 1000, 'usec' => 0]);

		if (!$this->ping()) throw new Exception("Unable to connect to server at '$host:$port'. Server is offline or did not respond to ping.");
	}

	public function __destruct() { socket_close($this->socket); }

	public function getInfo(): array {
		$response = $this->sendRequest(Opcode::Info);

		if (!$response) return [];
		
		$result = [];
		$offset = 0;

		$result['password'] = (bool) unpack('C', $response[$offset])[1];
		$offset += 1;
	
		$result['players'] = unpack('v', substr($response, $offset, 2))[1];
		$offset += 2;
	
		$result['maxplayers'] = unpack('v', substr($response, $offset, 2))[1];
		$offset += 2;

		function read($response, &$offset) {
			$length  = unpack('V', substr($response, $offset, 4))[1];
			$offset += 4;
			$str     = substr($response, $offset, $length);
			$offset += $length;
			return mb_convert_encoding(trim($str), 'UTF-8', 'ISO-8859-1'); // We may encounter some accents
		}
	
		$result['hostname'] = read($response, $offset);
		$result['gamemode'] = read($response, $offset);
		$result['language'] = read($response, $offset);
	
		return $result;
	}

	public function getRules(): array {
		$response = $this->sendRequest(Opcode::Rules);

		if (!$response) return [];

		$offset    = 0;
		$numRules  = unpack('v', substr($response, $offset, 2))[1];
		$offset   += 2;
		
		$result    = [];
		for ($i = 0; $i < $numRules; $i++) {
			$ruleLen  = unpack('C', $response[$offset])[1];
			$rule     = substr($response, ++$offset, $ruleLen);
			$offset  += $ruleLen;

			$ruleValueLen  = unpack('C', $response[$offset])[1];
			$ruleValue     = substr($response, ++$offset, $ruleValueLen);
			$offset       += $ruleValueLen;

			$result[$rule] = $ruleValue;
		}

		return $result;
	}

	// * open.mp servers don't reply to the detailed players packet
	public function getPlayers(bool $detailed = false): array {
		$response = $this->sendRequest($detailed ? Opcode::DetailedPlayers : Opcode::Players);

		if (!$response) return [];

		$offset       = 0;
		$playerCount  = unpack('v', substr($response, $offset, 2))[1];
		$offset      += 2;

		$players = [];
		for ($p = 0; $p < $playerCount; $p++) {
			$player = [];

			// The id comes first when getting a detailed list
			if ($detailed) {
				$player['id']  = unpack('C', substr($response, $offset, 1))[1];
				$offset       += 1;
			}

			// We always need the name
			$nameLength      = unpack('C', substr($response, $offset, 1))[1];
			$offset         += 1;
			$player['name']  = substr($response, $offset, $nameLength);
			$offset         += $nameLength;
			
			// And the score also
			$player['score']  = unpack('V', substr($response, $offset, 4))[1];
			$offset          += 4;
			
			// Ping comes last when getting a detailed list
			if ($detailed) {
				$player['ping']  = unpack('V', substr($response, $offset, 4))[1];
				$offset         += 4;
			}
			
			$players[] = $player;
		}

		return $players;
	}

	public function ping(): bool {
		$randomNumbers = random_bytes(4);
		return $this->sendRequest(Opcode::Ping, $randomNumbers) === $randomNumbers; // We're good if the server sent back the same random bytes as a response
	}

	public function query(Opcode $opcode): array|null {
		$method = match ($opcode) {
			Opcode::Info => 'getInfo',
			Opcode::Rules => 'getRules',
			Opcode::Players, Opcode::DetailedPlayers => 'getPlayers',
			default => null,
		};

		if ($method === 'getPlayers') return $this->$method($opcode === Opcode::DetailedPlayers);
		
		return $this->$method();
	}

	private function sendRequest(Opcode $opcode, ?string $extraData = null): ?string {
		$packet = $this->packet . $opcode->value;
		
		if (!is_null($extraData)) $packet .= $extraData;

		socket_sendto($this->socket, $packet, strlen($packet), 0, $this->host, $this->port);

		$response = '';
		$address  = '';
		$port     = 0;
		$result   = socket_recvfrom($this->socket, $response, 2048, 0, $address, $port);

		return $result ? substr($response, 11) : null; // Clip the header away and return what we actually need, or not
	}
}
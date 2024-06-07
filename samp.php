<?php
/* 
	05/06/2024 - https://github.com/VIRUXE

	Read more on SA-MP's Query mechanism: https://sampwiki.blast.hk/wiki/Query_Mechanism
*/

require('SampQuery.php');

header('Content-Type: application/json');
try {
	$opcodes = [];

	if (isset($_GET['opcodes']))
		foreach (str_split($_GET['opcodes']) as $code)
			if ($opcode = Opcode::tryFrom($code))
				$opcodes[] = $opcode;

	// Default to Ping if no valid opcodes provided
	if (empty($opcodes)) $opcodes[] = Opcode::Ping;

	// Handle potential c/d conflict
	$cIndex = array_search(Opcode::Players, $opcodes);
	$dIndex = array_search(Opcode::DetailedPlayers, $opcodes);

	// If either c or d exists, remove the other if present
	if ($cIndex !== false && $dIndex !== false) unset($opcodes[$cIndex < $dIndex ? $dIndex : $cIndex]); // Unset the later one

	$server = new SampQuery($_GET['host'] ?? '127.0.0.1', $_GET['port'] ?? 7777, 1000);

	$result = [];
	if (array_search(Opcode::DetailedPlayers, $opcodes)) { // If the 'd' packet is still present
		// We request the 'i' packet first to check if it's an open.mp server or not
		$rules = $server->query(Opcode::Rules);
		$result['rules'] = $rules;

		// If it is, then return packet 'c' - something is better than nothing, right?
		if ($rules && str_contains($rules['version'], 'omp')) {
			$players = $server->query(Opcode::Players);
			if ($players) $result['players'] = $players;
		}
	}

	foreach ($opcodes as $opcode) {
		$key = match ($opcode) {
			Opcode::Info => 'info',
			Opcode::Rules => 'rules',
			Opcode::Players, Opcode::DetailedPlayers => 'players',
			Opcode::Ping => 'online'
		};

		if (key_exists($key, $result)) continue;

		$result[$key] = $server->query($opcode);
	}

	echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
	http_response_code(500); 
	echo json_encode(['error' => ['message' => 'JSON Encoding Error: ' . $e->getMessage()]]); 
} catch (Exception $e) {
	http_response_code(500); 
	echo json_encode(['error' => ['message' => $e->getMessage()]]);
}

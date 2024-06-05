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

	// Default to Info if no valid opcodes provided
	if (empty($opcodes)) $opcodes[] = Opcode::Info;

	// Handle potential c/d conflict
	$cIndex = array_search(Opcode::Players, $opcodes);
	$dIndex = array_search(Opcode::DetailedPlayers, $opcodes);

	// If either c or d exists, remove the other if present
	if ($cIndex !== false && $dIndex !== false) unset($opcodes[$cIndex < $dIndex ? $dIndex : $cIndex]); // Unset the later one

	$server = new SampQuery($_GET['host'] ?? '127.0.0.1', $_GET['port'] ?? 7777, 1000);

	$result = [];
	foreach ($opcodes as $opcode) {
		$query = $server->query($opcode);

		if (!$query) continue;

		$result[match ($opcode) {
			Opcode::Info => 'info',
			Opcode::Rules => 'rules',
			Opcode::Players, Opcode::DetailedPlayers => 'players',
		}] = $query;
	}

	echo json_encode(count($result) > 1 ? $result : reset($result), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
	http_response_code(500); 
	echo json_encode([
		'error'   => true,
		'message' => 'JSON Encoding Error: ' . $e->getMessage()
	]); 
} catch (Exception $e) {
	http_response_code(500); 
	echo json_encode([
		'error'   => true,
		'code'    => $e->getCode(),
		'message' => $e->getMessage()
	]);
}

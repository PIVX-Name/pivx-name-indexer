<?php declare(strict_types=1);

// php -S router: a fake PIVX Core JSON-RPC that follows the chain and holds INCOME_WALLET
// (FAKENODE_WALLET): what a sync run asks of the node before it has a range of blocks to scan.
// Its tip is block FAKENODE_TIP, made just now. Used by epoch_gate_test.php for a run that ends
// without an error.

$req = json_decode((string)file_get_contents('php://input'), true) ?? [];
$tip = (int)getenv('FAKENODE_TIP');
$hash = hash('sha256', 'block ' . $tip);
$result = match ($req['method'] ?? '') {
    'mnsync' => ['RequestedMasternodeAssets' => 999, 'RequestedMasternodeAttempt' => 0],
    'listshieldaddresses' => [getenv('FAKENODE_WALLET')],
    'getconnectioncount' => 8,
    'getbestblockhash' => $hash,
    'getblockheader' => ['hash' => $hash, 'height' => $tip, 'time' => time(), 'confirmations' => 1],
    'getblockcount' => $tip,
    default => null,
};
header('Content-Type: application/json');
echo json_encode($result === null
    ? ['result' => null, 'error' => ['code' => -32601, 'message' => 'Method not found'], 'id' => $req['id'] ?? null]
    : ['result' => $result, 'error' => null, 'id' => $req['id'] ?? null]);

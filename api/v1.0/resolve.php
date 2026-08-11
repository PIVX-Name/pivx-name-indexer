<?php declare(strict_types=1);

use Indexer\Database;
use Indexer\RustRocksDBProxy;
use Indexer\SparseMerkleTree;
use Indexer\Protocol;

/**
 * @var array $uri
 * @var Database $db
 */

$lookupType = 'name';
if (!Protocol::isDomainNameValid($domainName = strtolower($uri[2] ?? ''))) {
    if (!isset($uri[3]) || !in_array($uri[2], ['reverse', 'owner'], true) ||
        // Validate $uri[3] - the actual value. $domainName still holds
        // strtolower($uri[2]) here, i.e. the literal word "reverse"/"owner"; the
        // destructuring that loads the real value runs only after this guard passes.
        // Neither value is lowercased on the way in, and neither should be: both
        // validators require canonical lowercase form (see isAddressWellFormed and
        // isValidEd25519Pubkey), which is what keeps the host and the circuit
        // agreeing on the bytes.
        ($uri[2] === 'reverse' && !Protocol::isAddressValid($uri[3])) ||
        ($uri[2] === 'owner' && !Protocol::isValidEd25519Pubkey($uri[3]))) {
        throw new RuntimeException(API_ERROR_WRONG_INCOMING_PARAM_VALUE, INT_EXC_API_ERROR);
    }
    [,,$lookupType, $domainName] = $uri;
}

$qAdd = '';
$request = [
    'extended' => false,
    'with_checkpoint' => false,
];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($raw = file_get_contents('php://input')) !== '') {
        $input = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
    }
    $qAdd = '';
    if (isset($input['extended']) && $input['extended'] === true) {
        $request['extended'] = true;
        $qAdd .= ', `domains`.`created_block_id`, `domains_history`.`domain_tx`';
    }

    if (isset($input['with_checkpoint']) && $input['with_checkpoint'] === true) {
        $request['with_checkpoint'] = $input['with_checkpoint'];
    }

    if ($request['extended'] === true || $request['with_checkpoint'] === true) {
        $qAdd .= ', `domains`.`updated_block_id`';
    }
}

$where = match ($lookupType) {
    'name' => ' `domains`.`domain_name` = {:target:}',
    'reverse' => ' `domains_history`.`target_address` = {:target:}',
    'owner' => ' `domains_history`.`owner_pubkey` = {:target:}',
    default => throw new RuntimeException(API_ERROR_INTERNAL)
};
$map = ['{:target:}' => $domainName];

$qr = $db->doPlainQuery(
    'SELECT `domains`.`domain_name`, `domains_history`.`target_address`, `domains_history`.`owner_pubkey`,
            `domains_history`.`price`, `domains`.`nonce`' . $qAdd .
    ' FROM `domains`
    LEFT JOIN `domains_history` ON `domains_history`.`domain_name` = `domains`.`domain_name`
                                AND `domains_history`.`domain_block_id` = `domains`.`updated_block_id`
                                AND `domains_history`.`nonce` = `domains`.`nonce`
    WHERE ' . $where,
    $map
);

if ($qr === false) {
    throw new RuntimeException(API_ERROR_INTERNAL, INT_EXC_DB);
}
if ($qr->num_rows === 0) {
    throw new RuntimeException(API_ERROR_NOT_FOUND, INT_EXC_API_ERROR);
}

$dbData = $qr->fetch_all(MYSQLI_ASSOC);

$rocksDb = new RustRocksDBProxy(INDEXER_ROCKSDB_PROXY_SOCKET_PATH);
$smt = new SparseMerkleTree($rocksDb);

$out = [];
foreach ($dbData as $oneDomain) {
    $out[] = $oneDomain;
    $index = array_key_last($out);

    // Fetch the proof and the root it was built from in a single call. Both come
    // from one database snapshot, so a commit landing mid-request can no longer
    // return a proof that fails to verify against the root beside it.
    $smtState = $smt->getProofWithRoot($oneDomain['domain_name']);

    // Convert the raw bytes to a clean JSON array of Hex strings for the API.
    //
    // The tree is compact now, so a proof is variable length and the sibling list
    // alone is no longer enough to verify one: a verifier also needs the depth and
    // the terminal kind. `merkle_proof` keeps its existing name and shape so current
    // consumers are unaffected, and the fields beside it carry the rest.
    $hexProof = [];
    foreach ($smtState['proof']['siblings'] as $siblingBytes) {
        // Pack the bytes back into a string, then convert to hex
        $hexProof[] = bin2hex(pack('C*', ...$siblingBytes));
    }
    // Get the current root
    if (!isset($input['with_checkpoint']) || $input['with_checkpoint'] === false) {
        $out[$index] += [
            'smt_root' => bin2hex(pack('C*', ...$smtState['root'])),
        ];
    }

    $out[$index] += [
        'merkle_proof' => $hexProof,
        'proof_depth' => $smtState['proof']['depth'],
        // "Occupied" or "Vacant", or {"Blocked":{"record":{…}}} when another domain
        // holds this slot in the compact tree.
        'proof_terminal' => $smtState['proof']['terminal'],
    ];

    if ($request['with_checkpoint']) {
        $qr = $db->doSelect(
            'checkpoints',
            ['idx', 'block_id', 'smt_root'],
            ['block_id' => ['sign' => '>=', 'value' => $oneDomain['updated_block_id']]],
            'ORDER BY `block_id` DESC LIMIT 1',
        );
        if ($qr === false) {
            throw new RuntimeException(API_ERROR_INTERNAL, INT_EXC_DB);
        }
        $out[$index]['checkpoint'] = $qr->fetch_assoc();
    }
}

apiAnswer('response', $lookupType !== 'name' ? $out : $out[0]);

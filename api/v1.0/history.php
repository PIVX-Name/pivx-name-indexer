<?php declare(strict_types=1);

use Indexer\Database;
use Indexer\Protocol;

/**
 * @var array $uri
 * @var Database $db
 */

if (!Protocol::isDomainNameValid($domainName = strtolower($uri[2] ?? ''))) {
    throw new RuntimeException(API_ERROR_WRONG_INCOMING_PARAM_VALUE, INT_EXC_API_ERROR);
}

$request = [
    'order' => 'desc',
    'count' => 100,
    'from_block_id' => 0,
];

if (($raw = file_get_contents('php://input')) !== '') {
    $input = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);

    if (isset($input['order']) && in_array($input['order'], ['asc', 'desc'], true)) {
        $request['order'] = $input['order'];
    }

    if (isset($input['from_block_id']) && ($ts = (int)$input['from_block_id']) > 0) {
        $request['from_block_id'] = $ts;
    }

    $request['count'] = getParamCount($input['count'] ?? 0, $request['count'], 500);
}

$where = [
    'domain_name' => $domainName,
    'domain_block_id' => ['sign' => '>', 'value' => $request['from_block_id']],
];
if ($request['order'] === 'desc') {
    if ($request['from_block_id'] !== 0) {
        $where['domain_block_id']['sign'] = '<';
    } else {
        unset($where['domain_block_id']);
    }
}

$qr = $db->doSelect(
    'domains_history',
    '*',
    $where,
    'ORDER BY `domain_block_id` ' . $request['order'] . ', `nonce` ' . $request['order'] . ' LIMIT ' . $request['count']
);

if ($qr === false) {
    throw new RuntimeException(API_ERROR_INTERNAL, INT_EXC_DB);
}

$out = [];
if ($qr->num_rows !== 0) {
    while ($r = $qr->fetch_assoc()) {
        unset($r['domain_name']);
        $out[] = $r;
    }
}

apiAnswer('response', $out);

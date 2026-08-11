<?php declare(strict_types=1);

use Indexer\Database;
use Indexer\SparseMerkleTree;
use Denpa\Bitcoin\Client as RpcClient;
use Denpa\Bitcoin\Exceptions\BadRemoteCallException;
use Denpa\Bitcoin\Responses\BitcoindResponse;

function logEvent(string $text, string $tag = '', string $file = 'debug'): void
{
    if (DEBUG || str_contains($file, 'debug') === false) {
        file_put_contents(
            WORKLOGS_PATH . '/' . PROJECT_NAME . '-' . $file . '.log',
            date('Y-m-d H:i:s') . ($tag !== '' ? ' - ' . $tag : '') . ': ' . $text . PHP_EOL,
            FILE_APPEND
        );
    }
}

function apiAnswer(string $head, string|array|int $data): void
{
    if ($head === 'error') {
        $data = [
            'error_message' => $data
        ];
    }

    $out = [$head => $data];

    try {
        echo json_encode($out, JSON_THROW_ON_ERROR);
    } catch (Exception) {
        apiAnswer('error', API_ERROR_INTERNAL);
    }

    exit;
}

function getParamCount(mixed $input, int $def = 20, int $max = 100): int
{
    if (($count = (int)($input ?? 0)) < 0) {
        apiAnswer('error', API_ERROR_WRONG_INCOMING_PARAM_VALUE);
    }

    if ($count === 0) {
        $count = $def;
    } elseif ($count > $max) {
        $count = $max;
    }

    return $count;
}

function getParams(array $params, Database $db): array
{
    $qr = $db->doSelect(
        'params',
        '*',
        ['param' => ['sign' => 'IN', 'value' => $params]]
    );
    if ($qr === false) {
        throw new RuntimeException('DB Error: can\'t get parameters from DB.');
    }
    $out = [];
    if ($qr->num_rows !== 0) {
        while ($r = $qr->fetch_assoc()) {
            $out[$r['param']] = $r['value'];
        }
    }
    return $out;
}

function setParams(array $params, Database $db): void
{
    $ins = [];
    foreach ($params as $param => $value) {
        $ins[] = [
            'param' => $param,
            'value' => $value
        ];
    }
    if ($db->doBulkInsert(
        'params',
        $ins,
        false,
        ['value']
    ) === false) {
        throw new RuntimeException('DB Error: can\'t set parameters to DB.');
    }
}

/**
 * @param string $type
 * @param Database $db
 * @return array
 * @throws RuntimeException
 */
// Do not forget to grant required privileges "GRANT XA_RECOVER_ADMIN on *.* to 'user'@'%';"
function getXATXList(string $type, Database $db): array
{
    if (($qr = $db->query('XA RECOVER')) === false) {
        throw new RuntimeException('Can\'t query XA RECOVER', INT_EXC_DB);
    }

    $type = PROJECT_NAME . '-' . $type;
    $result = [];

    while ($r = $qr->fetch_assoc()) {
        $txName = substr($r['data'], 0, $r['gtrid_length']);
        $txType = substr($r['data'], $r['gtrid_length'], $r['bqual_length']);
        if ($txType === $type || str_starts_with($txType, $type . '_')) {
            $result[] = [$txName, $txType];
        }
    }

    return $result;
}

/**
 * @param string $type
 * @param Database $db
 * @param SparseMerkleTree $smt
 * @param string $logfileName
 * @return bool
 * @throws Indexer\RustRocksDBProxyException
 * @throws RuntimeException
 */
function checkUncommitedXATX(string $type, Database $db, SparseMerkleTree $smt, string $logfileName): bool
{
    if (($xaList = getXATXList($type, $db)) !== []) {
        if (count($xaList) === 1) {
            logEvent(
                'Found uncommited TX (' . implode(',', $xaList[0]) . '), checking...',
                'DB Consistency',
                $logfileName
            );

            $xaOldRoot = $xaList[0][0];
            $xaCurrentRoot = $smt->getRootHex();
            $txCommand = $xaOldRoot === $xaCurrentRoot ? 'ROLLBACK' : 'COMMIT';
            if ($db->query('XA ' . $txCommand . ' \'' . implode('\',\'', $xaList[0]) . '\'', retries: 0) === false) {
                throw new RuntimeException('Can\'t commit XA transaction (consistency check)', INT_EXC_DB);
            }

            logEvent(
                'New data from last uncommited TX has been ' . ($txCommand === 'COMMIT' ? ' commited' : 'reverted'),
                'DB Consistency',
                $logfileName
            );

            return $txCommand === 'COMMIT';
        }

        throw new RuntimeException(
            'PANIC! Should never happen! There more than one uncommited XA transactions: ' .
            formatXATXList($xaList),
            INT_EXC_FATAL
        );
    }
    return false;
}

function formatXATXList(array $xaTXes): string
{
    $parts = [];
    foreach ($xaTXes as $index => $subArray) {
        $formattedValues = "'" . implode("', '", $subArray) . "'";
        $parts[] = "$index: $formattedValues";
    }

    return implode(' / ', $parts);
}

/**
 * @param RpcClient $rpc
 * @return void
 * @throws RuntimeException
 * @throws BadRemoteCallException
 */
function assertNodeIsGood(RpcClient $rpc): void
{
    // check mnsync to prevent false data
    /** @var BitcoindResponse $response */
    $response = $rpc->request('mnsync', 'status');
    $mnSync = $response->get();
    if (!isset($mnSync['RequestedMasternodeAssets']) || $mnSync['RequestedMasternodeAssets'] !== 999) {
        throw new RuntimeException('Blockchain node is not synced');
    }
    // END check mnsync

    // let's check that the node has the INCOME_WALLET viewing key imported, otherwise stop
    $response = $rpc->request('listshieldaddresses', true);
    $addrList = $response->get();
    if (is_string($addrList)) {
        $addrList = (array)$addrList;
    }
    $addrList = array_flip($addrList);
    if (!isset($addrList[INCOME_WALLET])) {
        throw new RuntimeException(
            'The Registrar INCOME_WALLET viewing key is not imported in the Blockchain Node. Stopping...',
            INT_EXC_FATAL
        );
    }
}

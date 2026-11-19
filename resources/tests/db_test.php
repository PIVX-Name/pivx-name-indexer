<?php declare(strict_types=1);

// The schema (resources/schema.sql, loaded by run.sh) and what reads it: the tree key the database
// generates for each name against Protocol::treeKey(), the REG path check through `domains`
// (Protocol::pathTaken()), and BlockScanner skipping a REG whose path is taken while it carries out
// the commands around it, with a stub node.
//
// Two names sharing a whole tree path are not known to exist, so the collision is forged: at the end
// `domain_key` is made a plain column and given another name's key. Run on a throwaway database
// only.

require __DIR__ . '/bootstrap.php';

use Denpa\Bitcoin\Client as RpcClient;
use Denpa\Bitcoin\Responses\BitcoindResponse;
use GuzzleHttp\Psr7\Response;
use Indexer\BlockScanner;
use Indexer\Database;
use Psr\Http\Message\ResponseInterface;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$m = mysqli_init();
// Integers as integers, as the indexer reads them.
$m->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, true);
[$dbHost, $dbPort] = explode(':', DB_CONFIG['host']);
$m->real_connect($dbHost, DB_CONFIG['user'], DB_CONFIG['pass'], DB_CONFIG['db_name'], (int)$dbPort);
$q = static fn(string $sql): array => ($r = $m->query($sql)) instanceof mysqli_result ? $r->fetch_all(MYSQLI_ASSOC) : [];
TestProtocol::init(new Database());

/**
 * A node that answers from $chain: block height => ['time' => int, 'tx' => [txid => outputs]], and
 * 'prev' => the hash it is built on, when not the block before it's. $hashes: block height => what
 * getblockhash answers instead of the block's hash.
 */
final class StubNode extends RpcClient
{
    public array $asked = [];

    public function __construct(private readonly array $chain, private readonly array $hashes = [])
    {
    }

    public function request(string $method, ...$params): ResponseInterface
    {
        $this->asked[] = $method;
        $result = match ($method) {
            'getblockhash' => array_key_exists($params[0], $this->hashes) ? $this->hashes[$params[0]] : hash('sha256', 'block ' . $params[0]),
            'getblock' => $this->block($params[0]),
            'viewshieldtransaction' => ['txid' => $params[0], 'outputs' => $this->outputs($params[0])],
        };

        return new BitcoindResponse(new Response(200, [], json_encode(['result' => $result, 'error' => null, 'id' => 0])));
    }

    private function block(string $hash): array
    {
        foreach ($this->chain as $height => $block) {
            if (hash('sha256', 'block ' . $height) === $hash) {
                $tx = [['txid' => hash('sha256', 'coinbase ' . $height), 'vShieldOutput' => []]];
                foreach (array_keys($block['tx']) as $txid) {
                    $tx[] = ['txid' => $txid, 'vShieldOutput' => [['cmu' => 'x']]];
                }
                return ['hash' => $hash, 'confirmations' => 10, 'time' => $block['time'], 'tx' => $tx,
                    'previousblockhash' => $block['prev'] ?? hash('sha256', 'block ' . ($height - 1))];
            }
        }
        throw new RuntimeException('no such block');
    }

    private function outputs(string $txid): array
    {
        foreach ($this->chain as $block) {
            if (isset($block['tx'][$txid])) {
                return $block['tx'][$txid];
            }
        }
        throw new RuntimeException('no such tx');
    }
}

section('schema');
$col = $q("SELECT EXTRA, COLUMN_TYPE, IS_NULLABLE FROM information_schema.columns WHERE table_schema = DATABASE()
    AND table_name = 'domains' AND column_name = 'domain_key'")[0] ?? [];
check(($col['EXTRA'] ?? '') === 'STORED GENERATED' && ($col['COLUMN_TYPE'] ?? '') === 'binary(16)' &&
    ($col['IS_NULLABLE'] ?? '') === 'NO', 'domains.domain_key: binary(16), stored, generated');
$idx = static fn(string $table, string $name): array => $q("SELECT NON_UNIQUE, COLUMN_NAME FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = '$table' AND index_name = '$name'");
check(($idx('domains', 'domain_key')[0]['NON_UNIQUE'] ?? null) === 0, 'domains: a unique key on domain_key');
check(($idx('domains_history', 'domain_block_id')[0]['COLUMN_NAME'] ?? '') === 'domain_block_id',
    'domains_history: a key on domain_block_id');
check(($idx('checkpoints', 'smt_root')[0]['COLUMN_NAME'] ?? '') === 'smt_root', 'checkpoints: a key on smt_root');

section('Database inserts: one row, many rows, their ON DUPLICATE KEY UPDATE');
$ins = new Database();
$market = static fn(): array => $q('SELECT domain_name, price, block_id, nonce FROM marketplace ORDER BY domain_name');
check($ins->doBulkInsert('marketplace', [
    ['domain_name' => "o'q.pivx", 'price' => 5, 'block_id' => 1, 'nonce' => 0],
    ['domain_name' => 'b\\s.pivx', 'price' => PHP_INT_MAX, 'block_id' => 2, 'nonce' => 1],
]) === true, 'two rows in one statement, a quote and a backslash in them');
check($ins->doBulkInsert('marketplace', [
    ['domain_name' => "o'q.pivx", 'price' => 6, 'block_id' => 3, 'nonce' => 9],
    ['domain_name' => 'c.pivx', 'price' => 7, 'block_id' => 4, 'nonce' => 2],
], false, ['price', 'block_id']) === true, 'two more, one of them there: updated');
check($ins->doBulkInsert('marketplace', [['domain_name' => 'c.pivx', 'price' => 1, 'block_id' => 1, 'nonce' => 1]], true) === true,
    'one there, IGNORE: left as it is');
check($ins->doInsert('marketplace', ['domain_name' => 'c.pivx', 'price' => 8, 'block_id' => 9, 'nonce' => 0], false, ['price'],
    '`nonce` = `marketplace`.`nonce` + 10') === true, 'one row there: updated by the list and the extra');
check($ins->doInsert('marketplace', ['domain_name' => 'c.pivx', 'price' => 1, 'block_id' => 1, 'nonce' => 1], false, [],
    '`block_id` = `sysdbins`.`block_id` + 100') === true, 'one row there: updated by the extra alone');
check($ins->doInsert('marketplace', ['domain_name' => 'd.pivx', 'price' => 0, 'block_id' => 0, 'nonce' => 0]) === true, 'one new row');
check($market() === [
    ['domain_name' => 'b\\s.pivx', 'price' => PHP_INT_MAX, 'block_id' => 2, 'nonce' => 1],
    ['domain_name' => 'c.pivx', 'price' => 8, 'block_id' => 101, 'nonce' => 12],
    ['domain_name' => 'd.pivx', 'price' => 0, 'block_id' => 0, 'nonce' => 0],
    ['domain_name' => "o'q.pivx", 'price' => 6, 'block_id' => 3, 'nonce' => 0],
], 'the rows are what those made of them');
// The history of a full sync goes in like this: 20000 rows built and sent in one statement.
$many = [];
for ($i = 0; $i < 20000; $i++) {
    $many[] = ['domain_name' => 'n' . $i . '.pivx', 'price' => $i, 'block_id' => $i, 'nonce' => $i];
}
$t = microtime(true);
check($ins->doBulkInsert('marketplace', $many) === true && $q('SELECT COUNT(*) AS n FROM marketplace')[0]['n'] === 20004,
    sprintf('20000 rows in one statement (%.2f s)', microtime(true) - $t));
$q('DELETE FROM marketplace');
$ins->free();

section('the key the database generates is Protocol::treeKey()');
$names = ['alice.pivx', 'bob.pivx', 'a.pivx', str_repeat('z', 59) . '.safe', 'x-y.private', '0.secure'];
for ($i = 0; $i < 200; $i++) {
    $names[] = 'n' . $i . '.pivx';
}
foreach ($names as $i => $n) {
    $m->query("INSERT INTO domains (domain_name, created_block_id, updated_block_id, created_ts, updated_ts, nonce)
        VALUES ('$n', 1, 1, 1, 1, $i + 1)");
}
$bad = 0;
foreach ($q('SELECT domain_name, HEX(domain_key) AS k FROM domains') as $r) {
    if (strtolower($r['k']) !== bin2hex(TestProtocol::treeKey($r['domain_name']))) {
        $bad++;
    }
}
check($bad === 0 && count($names) === $q('SELECT COUNT(*) AS c FROM domains')[0]['c'],
    count($names) . ' names, the same key on both sides');
check($q("SELECT HEX(domain_key) AS k FROM domains WHERE domain_name = 'alice.pivx'")[0]['k'] ===
    '485017EFA5D28FA0BCD37AC0570FC45D', 'alice.pivx: pins-core\'s key_of()');
check(!TestProtocol::pathTaken('n7.pivx') && !TestProtocol::pathTaken('free.pivx'),
    'pathTaken(): a name is not in its own way, and a free path is free');

section('a REG whose path another name holds in `domains` (forged)');
$m->query('ALTER TABLE domains MODIFY domain_key binary(16) NOT NULL');
$victim = 'victim.pivx';
$m->query("UPDATE domains SET domain_key = UNHEX('" . bin2hex(TestProtocol::treeKey($victim)) . "') WHERE domain_name = 'n1.pivx'");
try {
    $m->query("INSERT INTO domains (domain_name, created_block_id, updated_block_id, created_ts, updated_ts, nonce, domain_key)
        VALUES ('second.pivx', 1, 1, 1, 1, 1, UNHEX('" . bin2hex(TestProtocol::treeKey($victim)) . "'))");
    check(false, 'the unique key refuses a second name on a path');
} catch (mysqli_sql_exception $e) {
    check($e->getCode() === 1062, 'the unique key refuses a second name on a path');
}
TestProtocol::seed([]);
check(TestProtocol::pathTaken($victim), 'pathTaken() finds n1.pivx on its path');
$A = signer('db test owner A');
$t = 1780000000;
$regOf = static fn(string $n, int $nonce): string => memo(['REG', $n, address(0), $A['pub'], $nonce], $A['sk']);
$price = static fn(string $n): int => TestProtocol::getDomainPrice($n, 'REG');
check(TestProtocol::parseCommand($regOf($victim, $t - 100), $price($victim), $t) === [], 'its REG is refused');
check(!TestProtocol::pathTaken('n1.pivx'), 'n1.pivx is not in its own way');

section('BlockScanner: the REG is skipped, the commands around it carried out');
TestProtocol::seed([]);
// ccc.pivx's path is held by a name this run has carried out already, not in the database yet.
TestProtocol::forgePath('ccc.pivx', 'ddd.pivx');
$pay = static fn(string $memo, int $amount): array => [
    ['output' => 0, 'address' => INCOME_WALLET, 'memoStr' => $memo, 'valueSat' => $amount],
];
$node = new StubNode([
    100 => ['time' => $t, 'tx' => [
        'aa01' => $pay($regOf('aaa.pivx', $t - 100), $price('aaa.pivx')),
        'aa02' => $pay($regOf($victim, $t - 99), $price($victim)),
        'aa03' => $pay($regOf('bbb.pivx', $t - 98), $price('bbb.pivx')),
    ]],
    101 => ['time' => $t + 60, 'tx' => [
        'aa04' => $pay($regOf('ccc.pivx', $t - 97), $price('ccc.pivx')),
        'aa05' => $pay($regOf('aaa.pivx', $t - 96), $price('aaa.pivx')),
        'aa06' => $pay($regOf('eee.pivx', $t - 95), $price('eee.pivx')),
    ]],
]);
$seen = [];
$commands = BlockScanner::scan($node, 100, 101, static function (int $b, string $h, bool $none) use (&$seen): void {
    $seen[] = $b;
});
check(array_column($commands, 'tx_id') === ['aa01', 'aa03', 'aa06'],
    'carried out: aaa, bbb, eee; skipped: the forged two and aaa\'s second REG');
check($seen === [100, 101], 'both blocks scanned to the end');
check(TestProtocol::pathHolders()[TestProtocol::treeKey('eee.pivx')] === 'eee.pivx', 'eee.pivx holds its path now');
$row = BlockScanner::historyRow($commands[0]);
check($row['domain_name'] === 'aaa.pivx' && $row['op'] === 'REG' && $row['domain_block_id'] === 100 &&
    $row['domain_tx'] === 'aa01', 'the history row of the first');

section('BlockScanner: the blocks read make one chain');
// $scan: the blocks scanned to the end, and the message scan() ended with ('' when it did not).
$scan = static function (StubNode $node, ?string $after = null): array {
    $seen = [];
    try {
        BlockScanner::scan($node, 200, 202, static function (int $b) use (&$seen): void {
            $seen[] = $b;
        }, $after);
        return [$seen, ''];
    } catch (RuntimeException $e) {
        return [$seen, $e->getMessage()];
    }
};
$empty = ['time' => $t, 'tx' => []];
$chain = [200 => $empty, 201 => $empty, 202 => $empty];
check($scan(new StubNode($chain)) === [[200, 201, 202], ''], 'three blocks, each built on the one before');
check($scan(new StubNode($chain), hash('sha256', 'block 199')) === [[200, 201, 202], ''],
    'the first built on the hash given for the block before the range');
[$seen, $error] = $scan(new StubNode($chain), hash('sha256', 'block 199 of another branch'));
check($seen === [] && str_contains($error, 'Block 200 (') && str_contains($error, 'reorganised during the scan'),
    'the first built on another hash than the one given: nothing scanned');
[$seen, $error] = $scan(new StubNode([201 => $empty + ['prev' => hash('sha256', 'block 200 of another branch')]] + $chain));
check($seen === [200] && str_contains($error, 'Block 201 (') && str_contains($error, 'not on ' . hash('sha256', 'block 200')),
    'a block of another branch in the middle: the scan stops before it');
foreach ([
    'a number' => 7,
    'an array' => ['x'],
    'null' => null,
    'upper case hex' => strtoupper(hash('sha256', 'block 201')),
    'hex and a newline' => hash('sha256', 'block 201') . "\n",
    '63 hex digits' => substr(hash('sha256', 'block 201'), 1),
] as $what => $answer) {
    [$seen, $error] = $scan(new StubNode($chain, [201 => $answer]));
    // What scan() is given is what Denpa's get() makes of the answer: the one element of a
    // one-element array, [] of null.
    $given = (new BitcoindResponse(new Response(200, [], json_encode(['result' => $answer, 'error' => null, 'id' => 0]))))->get();
    check($seen === [200] && str_starts_with($error, 'Got wrong \'getblockhash\' response: ' . json_encode($given)),
        'getblockhash answering ' . $what . ': refused, as an error to retry');
}

done();

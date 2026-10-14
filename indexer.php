<?php declare(strict_types=1);

use Indexer\Database;
use Indexer\Evm;
use Indexer\EvmRpcException;
use Indexer\Protocol;
use Indexer\RustRocksDBProxy;
use Indexer\SparseMerkleTree;
use Indexer\LockFileUtils;
use Denpa\Bitcoin\Client as RpcClient;
use Denpa\Bitcoin\Exceptions\BadRemoteCallException;
use Denpa\Bitcoin\Responses\BitcoindResponse;
use Telegram\Bot\Api;

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/config.php';
require __DIR__ . '/include/functions.php';

$command = $argv[1] ?? '';
$watchdogLockFile = __DIR__ . '/resources/sync.lock';

try {
    if ($command === 'watchdog') {
        // Read-only: report a stalled lock without ever competing for it. See watchdog.php.
        // Dispatched before the Database and rr-proxy objects are built so the watchdog
        // stays independent of them. Both constructors are lazy today, so this changes
        // nothing at runtime; it keeps the watchdog working if either ever starts
        // connecting eagerly, which is exactly when it would be needed most.
        $watchdogInspectOnly = true;
        include __DIR__ . '/watchdog.php';
        exit;
    }

    // Moved inside the try so that any future eager connection failure is logged and
    // notified like other errors instead of dying uncaught before the handler exists.
    $db = new Database();
    Protocol::init($db);
    $rocksDb = new RustRocksDBProxy(INDEXER_ROCKSDB_PROXY_SOCKET_PATH);
    $smt = new SparseMerkleTree($rocksDb);

    if ($command === 'clean') {
        if (($argv[2] ?? '') !== 'confirm') {
            echo 'Warning!!!' . PHP_EOL .
                'You\'re about to clean all the databases to prepare it for the from-the-scratch synchronization.' . PHP_EOL .
                'Make sure you understand what you\'re doing! Please add "confirm" word after the "clean" command to process.' . PHP_EOL;
            exit;
        }
        foreach (INDEXER_DB_TABLES as $oneTable) {
            if ($db->query('TRUNCATE TABLE `' . $oneTable . '`') === false) {
                throw new RuntimeException('Can\'t clean the table "' . $oneTable . '"', INT_EXC_DB);
            }
        }
        if ($db->doInsert(
            'checkpoints',
            [
                // idx 0 is the contract's genesis root (rootAt[0]) — the empty-registry root
                // it was deployed with. Discovery resumes from the highest idx, so this row is
                // the anchor the whole ascending walk hangs from.
                'idx' => 0,
                'block_id' => COIN_GENESIS_BLOCK,
                'smt_root' => SMT_GENESIS_ROOT,
            ]
        ) === false) {
            throw new RuntimeException('Can\'t insert Genesis Root to checkpoints', INT_EXC_DB);
        }

        setParams([
            PARAM_LAST_PROCESSED_COIN_BLOCK => COIN_GENESIS_BLOCK,
        ], $db);

        if ($rocksDb->clear() === false) {
            throw new RuntimeException('Can\'t clean RocksDB database', INT_EXC_DB);
        }

        echo 'The database has been successfully cleaned.' . PHP_EOL;
        exit;
    }
    if ($command !== 'sync') {
        echo '--== ' . PROJECT_NAME . ' Domain name Indexer ==--' . PHP_EOL .
            'Supported commands:' . PHP_EOL .
            'sync - start the indexer sync iteration (the way it\'s started from Systemd timer service)' . PHP_EOL .
            'watchdog - report a stalled sync lock (the way it\'s started from Systemd watchdog timer)' . PHP_EOL .
            'clean - perform the database clean for fresh initialization from on-chain data' . PHP_EOL;
        exit;
    }

    // Lock the execution more than one script at one moment
    $watchdogInspectOnly = false;
    include __DIR__ . '/watchdog.php';

    // check if we have at least one RPC_URL added to work with
    if (($qr = $db->doSelect(
        'rpc_list',
        '`rpc_url`',
        ['chain_id' => EVM_NETWORK['chain_id']]
    )) === false) {
        throw new RuntimeException('Can\'t query rpc_list', INT_EXC_DB);
    }
    if ($qr->num_rows === 0) {
        throw new RuntimeException(
            'Can\'t start. Please, add at least one RPC endpoint (rpc_list DB table)',
            INT_EXC_FATAL
        );
    }

    $evm = new Evm(EVM_NETWORK, $db);

    // The newest checkpoint we hold locally. `idx` is the position in the contract's
    // root chain (`rootAt` by index, 0 = genesis), which is what makes discovery resumable.
    if (($qr = $db->doSelect(
        'checkpoints',
        ['idx', 'block_id', 'smt_root'],
        [],
        'ORDER BY `idx` DESC LIMIT 1'
    )) === false) {
        throw new RuntimeException('Can\'t query checkpoints', INT_EXC_DB);
    }
    if ($qr->num_rows === 0) {
        throw new RuntimeException(
            'Genesis SMT root is not found in the database. It seems the initialization with "clean" command hasn\'t been executed properly.',
            INT_EXC_FATAL
        );
    }
    $checkpoint = $qr->fetch_assoc();

    // Check the data consistency in MySQL and RocksDB (if there's any uncommited MySQL transaction).
    // It can happen when commit to RocksDB or to MySQL on the last sync has failed.
    checkUncommitedXATX('indexer', $db, $smt, 'indexer.debug');

    $requestedParams = [PARAM_LAST_PROCESSED_COIN_BLOCK];
    $params = getParams($requestedParams, $db);
    if (count($params) !== count($requestedParams)) {
        throw new RuntimeException(
            'One or several required parameters are absent in "params" table.',
            INT_EXC_FATAL
        );
    }

    // --- CHECKPOINT DISCOVERY ------------------------------------------------------
    //
    // Checkpoints are read ascending from the anchor contract's root chain (`rootAt` by
    // index). The idx column is the resume cursor, so an interrupted walk continues where it
    // stopped.
    //
    // Best-effort by design: an RPC failure here must not prevent the blockchain sync
    // below from processing the checkpoints already stored. Discovery fills the work
    // queue; the sync consumes it. A short queue means less work this run, never none.
    try {
        // Pin every read below to one finalised height before the walk starts.
        //
        // Reading the chain tip would let a checkpoint that a reorg then discards be
        // written to a table the reconcile path treats as authoritative: the next run sees
        // the stored root disagree with the chain, deletes rows and force-stops demanding a
        // rebuild. On BSC a one-block reorg is ordinary, so that turns routine chain
        // behaviour into an outage. Pinning also keeps the length probe and the fetches
        // that follow it on the same view, which reading the tip per call would not.
        $pinned = $evm->pinReadBlock();
        logEvent(
            'Reading checkpoints at EVM block ' . $pinned['block'] . ' (' . $pinned['mode'] . ')',
            'EVM Checkpoints',
            'indexer.debug'
        );

        // Epoch gate — the new contract's client-safety marker.
        //
        // `epoch` advances whenever the anchored state moves by anything other than a proven
        // batch under unchanged rules: a rules change, or a rollback. Either re-points names
        // without a fresh owner signature, so the indexer must pin the epoch it has reviewed
        // and refuse to follow the chain under a newer one until an operator has looked at
        // what changed. EVM_NETWORK['expected_epoch'] is that pin; bump it only after
        // reviewing the change on-chain. Reading it from anchoredState() ties the epoch to the
        // root it guards (same block). A legitimate rollback trips this first, by design: the
        // operator reviews, bumps expected_epoch, and on the next run the reconcile below
        // discards the rolled-back checkpoints and force-stops for a rebuild.
        $anchored = $evm->getAnchoredState();
        if ($anchored['epoch'] !== EVM_NETWORK['expected_epoch']) {
            throw new RuntimeException(
                'On-chain epoch ' . $anchored['epoch'] . " does not match the reviewed "
                . "EVM_NETWORK['expected_epoch'] = " . EVM_NETWORK['expected_epoch'] . '. A rules '
                . 'change or rollback the operator has not reviewed is in force; refusing to '
                . 'follow the chain until expected_epoch is updated. :FORCE-STOP:',
                INT_EXC_FATAL
            );
        }

        $chainLength = $evm->getCheckpointCount();
        $localNext = $checkpoint['idx'] + 1;

        // A rollback rewrites history below our high-water mark: the chain gets shorter,
        // or an index we already hold now carries a different root.
        $anchorOk = $chainLength >= $localNext;
        if ($anchorOk) {
            $anchor = $evm->getCheckpointAt($checkpoint['idx']);
            $anchorOk = $anchor !== null && $anchor['root'] === $checkpoint['smt_root'];
        }

        if (!$anchorOk) {
            logEvent(
                'Anchor mismatch at idx ' . $checkpoint['idx'] . ' — the contract rolled back; reconciling',
                'EVM Checkpoints',
                'indexer.debug'
            );

            $keep = -1;
            for ($i = min($checkpoint['idx'], max($chainLength - 1, 0)); $i >= 0; $i--) {
                $onChain = $evm->getCheckpointAt($i);
                if ($onChain === null) {
                    continue;
                }
                if (($qr = $db->doSelect('checkpoints', ['smt_root'], ['idx' => $i])) === false) {
                    throw new RuntimeException('Can\'t query checkpoint for reconcile', INT_EXC_DB);
                }
                $localRoot = $qr->num_rows === 1 ? $qr->fetch_assoc()['smt_root'] : null;
                if ($localRoot !== null && $localRoot === $onChain['root']) {
                    $keep = $i;
                    break;
                }
            }

            // Genesis is immutable: the contract was deployed with it and no rollback can
            // pop index 0. So "no index agrees, not even 0" cannot describe a real chain —
            // it means the reads themselves were unreliable. Deleting on that reading is
            // how an endpoint outage once wiped a node's entire checkpoint table.
            if ($keep < 0) {
                throw new RuntimeException(
                    'Reconcile found no agreeing checkpoint, not even genesis — refusing to '
                    . 'discard anything. The chain reads are unreliable; retrying next run.'
                );
            }

            // Everything above the agreement point describes a state the contract no
            // longer attests to, so it must not stay in a table that resolve() cites.
            if ($db->doDelete('checkpoints', ['idx' => ['sign' => '>', 'value' => $keep]]) === false) {
                throw new RuntimeException('Failed to discard rolled-back checkpoints', INT_EXC_DB);
            }
            throw new RuntimeException(
                'Rolled back to checkpoint idx ' . $keep . '. Local domain state is now ahead of the '
                . 'anchored chain and must be rebuilt: run "indexer clean confirm". :FORCE-STOP:',
                INT_EXC_FATAL
            );
        }

        // Fetch forward, persisting each chunk so an interruption keeps what it got.
        $insData = [];
        for ($idx = $localNext; $idx < $chainLength; $idx++) {
            $cp = $evm->getCheckpointAt($idx);
            if ($cp === null) {
                break;
            }

            $insData[] = [
                'idx' => $idx,
                'block_id' => $cp['block_id'],
                'smt_root' => $cp['root'],
            ];
            $checkpoint['idx'] = $idx;
            $checkpoint['block_id'] = $cp['block_id'];
            $checkpoint['smt_root'] = $cp['root'];

            if (count($insData) >= EVM_BATCH_SIZE || $idx === $chainLength - 1) {
                if ($db->doBulkInsert('checkpoints', $insData) === false) {
                    throw new RuntimeException('Bulk insert to checkpoints', INT_EXC_DB);
                }
                logEvent(
                    count($insData) . ' checkpoint(s) committed, through idx ' . $idx,
                    'EVM Checkpoints',
                    'indexer.debug'
                );
                $insData = [];
            }
        }
    } catch (Throwable $e) {
        // A rollback, an unreviewed epoch change, or a database failure is a real fault and
        // must surface rather than being swallowed as a best-effort discovery miss.
        if (in_array($e->getCode(), [INT_EXC_FATAL, INT_EXC_DB], true)) {
            throw $e;
        }

        // The ONLY place this file rotates a failing endpoint. Every call that can raise
        // EvmRpcException is inside this try, so the top-level handler can never see one —
        // catching it here without marking the endpoint would pin the indexer to a dead
        // RPC run after run, discovery permanently stalled behind a single log line.
        // Anything added later that calls Evm outside this block needs its own handling.
        if ($e instanceof EvmRpcException) {
            $evm->setRpcUrl(true);
        }

        logEvent(
            'Checkpoint discovery stopped early: ' . $e->getMessage()
            . '. Continuing with checkpoints up to idx ' . $checkpoint['idx'],
            'EVM Checkpoints',
            'indexer.debug'
        );
    }

    $rpc = new RpcClient(RPC_CONFIG);

    // check the node is synced, the Registrar wallet exists (viewing key is imported)
    assertNodeIsGood($rpc);

    // syncing blockchain to the latest checkpoint, validating commands, update domains DB and SMT root
    if ($checkpoint['block_id'] > $params[PARAM_LAST_PROCESSED_COIN_BLOCK]) {
        logEvent(
            'There is new checkpoint block to sync to: ' . $checkpoint['block_id'],
            'Blockchain sync',
            'indexer.debug'
        );

        $bcSynced = false;
        $bcLastCheckpointBlockId = $params[PARAM_LAST_PROCESSED_COIN_BLOCK];
        while ($bcSynced === false) {
            if (($qr = $db->doSelect(
                'checkpoints',
                ['block_id', 'smt_root'],
                ['block_id' => ['sign' => '>', 'value' => $bcLastCheckpointBlockId]],
                'ORDER BY `block_id` ASC LIMIT 1'
            )) === false) {
                throw new RuntimeException('Can\'t query next checkpoint for sync', INT_EXC_DB);
            }
            if ($qr->num_rows === 0) {
                $bcSynced = true;
                logEvent(
                    'Synchronization is ended',
                    'Blockchain sync',
                    'indexer.debug'
                );
                continue;
            }
            $smtOldRoot = $smt->getRootHex();
            $lastCheckpoint = $qr->fetch_assoc();

            $newData = [];
            for ($block = ++$bcLastCheckpointBlockId; $block <= $lastCheckpoint['block_id']; $block++) {
                /** @var BitcoindResponse $response */
                $response = $rpc->request('getblockhash', $block);
                $blockHash = $response->get();
                if (strlen($blockHash) !== 64) {
                    throw new RuntimeException('Got wrong \'getblockhash\' response: ' . $blockHash);
                }

                /** @var BitcoindResponse $response */
                $response = $rpc->request('getblock', $blockHash, 2);
                $blockData = $response->get();

                if (!isset($blockData['tx'], $blockData['confirmations']) ||
                    !is_array($blockData['tx']) || $blockData['tx'] === [] ||
                    !is_int($blockData['confirmations']) || $blockData['confirmations'] < 1 ||
                    (($blockData['hash'] ?? '') !== $blockHash)) {
                    throw new RuntimeException(
                        'TX list of the block "' . $block . '" can\'t absent or be empty'
                    );
                }

                foreach ($blockData['tx'] as $oneBlockTx) {
                    if (!isset($oneBlockTx['vShieldOutput']) || $oneBlockTx['vShieldOutput'] === []) {
                        continue;
                    }

                    try {
                        /** @var BitcoindResponse $response */
                        $response = $rpc->request('viewshieldtransaction', $oneBlockTx['txid']);
                        $saplingTxInfo = $response->get();

                        if (!isset($saplingTxInfo['txid'])) {
                            throw new RuntimeException('Failed to get info of shielded TX: ' . $oneBlockTx['txid']);
                        }
                        // Strict check: Should never happen, just in case
                        if ($saplingTxInfo['txid'] !== $oneBlockTx['txid']) {
                            throw new RuntimeException(
                                'Strange txid "' . $saplingTxInfo['txid'] . '" in shielded tx: ' . $oneBlockTx['txid']
                            );
                        }

                        // Strict check: Should never happen, just in case
                        if (!isset($saplingTxInfo['outputs'])) {
                            if (!isset($saplingTxInfo['inputs'])) {
                                throw new RuntimeException(
                                    'TX "' . $oneBlockTx['txid'] . '" have no inputs/outputs',
                                    INT_EXC_FATAL
                                );
                            }
                            continue;
                        }

                        foreach ($saplingTxInfo['outputs'] as $oneTxOutput) {
                            if ($oneTxOutput['address'] === INCOME_WALLET && isset($oneTxOutput['memoStr']) &&
                                ($txValue = Protocol::convertAmount((string)$oneTxOutput['value'])) &&
                                ($commandData = Protocol::parseCommand(
                                    $oneTxOutput['memoStr'],
                                    $txValue,
                                    $blockData['time']
                                )) !== []) {
                                // ignore TX with wrong coin amount
                                if (Protocol::paymentAmountValid($commandData, $txValue) === false) {
                                    Protocol::clearDomainState();
                                    continue;
                                }

                                Protocol::commitDomainState($commandData['domain_name']);

                                $commandData['tx_id'] = $oneBlockTx['txid'];
                                $commandData['block_id'] = $block;
                                $newData[] = $commandData;

                                // there can't be more than one command in the same tx
                                break;
                            }
                        }
                    } catch (BadRemoteCallException $e) {
                        if ($e->getCode() === -5 &&
                            ($e->getMessage() === 'Invalid or non-wallet transaction id' ||
                                $e->getMessage() === 'Invalid transaction, no shield data available')) {
                            continue;
                        }
                        throw $e;
                    }
                }
            }

            if ($db->xaTXStart([$smtOldRoot, 'indexer']) === false) {
                throw new RuntimeException('Starting XA-TX', INT_EXC_DB);
            }
            $rocksDb->beginTransaction();

            foreach ($newData as $oneNewData) {
                // debug logging
                if (DEBUG) {
                    $debugLogData = array_map(
                        static fn($k, $v) => "$k: $v",
                        array_keys($oneNewData),
                        $oneNewData
                    );
                    logEvent(
                        implode(', ', $debugLogData),
                        'Received valid domain operation command',
                        'indexer.debug'
                    );
                }

                if ($oneNewData['op'] === Protocol::OP_REG) {
                    if (($qr = $db->doInsert(
                        'domains',
                        [
                            'domain_name' => $oneNewData['domain_name'],
                            'created_block_id' => $oneNewData['block_id'],
                            'updated_block_id' => $oneNewData['block_id'],
                            'nonce' => $oneNewData['nonce'],
                        ],
                        true
                    )) === false) {
                        throw new RuntimeException('Inserting new domain', INT_EXC_DB);
                    }
                    // Strict check: Should never happen, just in case
                    if ($db->affectedRows() === 0) {
                        throw new RuntimeException(
                            'CRITICAL! Inserting existing domain',
                            INT_EXC_FATAL
                        );
                    }
                } else {
                    if (($qr = $db->doUpdate(
                        'domains',
                        [
                            'updated_block_id' => $oneNewData['block_id'],
                            'nonce' => $oneNewData['nonce'],
                        ],
                        ['domain_name' => $oneNewData['domain_name']],
                    )) === false) {
                        throw new RuntimeException('Updating domain', INT_EXC_DB);
                    }
                    if ($db->affectedRows() === 0) {
                        throw new RuntimeException(
                            'CRITICAL! The domain "' . $oneNewData['domain_name'] .
                            '" should exist and should be updated!!!',
                            INT_EXC_FATAL
                        );
                    }

                    if ($oneNewData['op'] === Protocol::OP_LST) {
                        if (($qr = $db->doInsert(
                            'marketplace',
                            [
                                'domain_name' => $oneNewData['domain_name'],
                                'price' => $oneNewData['price'],
                                'block_id' => $oneNewData['block_id'],
                                'nonce' => $oneNewData['nonce'],
                            ],
                            true
                        )) === false) {
                            throw new RuntimeException(
                                'Inserting ' . $oneNewData['domain_name'] . ' to marketplace',
                                INT_EXC_DB
                            );
                        }
                        // Strict check: Should never happen, just in case
                        if ($db->affectedRows() === 0) {
                            throw new RuntimeException(
                                'CRITICAL! Inserting existing domain ' . $oneNewData['domain_name'] . ' to marketplace',
                                INT_EXC_FATAL
                            );
                        }
                    } elseif (in_array($oneNewData['op'], [Protocol::OP_ULT, Protocol::OP_BUY], true)) {
                        if (($qr = $db->doDelete(
                            'marketplace',
                            [
                                'domain_name' => $oneNewData['domain_name'],
                            ]
                        )) === false) {
                            throw new RuntimeException(
                                'Deleting ' . $oneNewData['domain_name'] . ' form marketplace',
                                INT_EXC_DB
                            );
                        }
                        // Strict check: Should never happen, just in case
                        if ($db->affectedRows() === 0) {
                            throw new RuntimeException(
                                'CRITICAL! The domain ' . $oneNewData['domain_name'] . ' must be deleted from marketplace',
                                INT_EXC_FATAL
                            );
                        }
                    }
                }

                // common part for any domain update operation: inserting latest and actual domain info
                $oneDomainIns = [
                    'domain_name' => $oneNewData['domain_name'],
                    'domain_block_id' => $oneNewData['block_id'],
                    'target_address' => $oneNewData['target_address'],
                    'owner_pubkey' => $oneNewData['pubkey'],
                    'domain_tx' => $oneNewData['tx_id'],
                    'op' => $oneNewData['op'],
                    'nonce' => $oneNewData['nonce'],
                    'price' => $oneNewData['price'],
                ];

                if (($qr = $db->doInsert(
                    'domains_history',
                    $oneDomainIns
                )) === false) {
                    throw new RuntimeException('Inserting new domain history', INT_EXC_DB);
                }

                // --- SMT SEQUENCE STEP B: Move Time Forward ---
                // The daemon derives the leaf hash from these fields and rehashes the
                // spine, so the next transaction gets the mathematically correct state.
                $smt->update(
                    $oneNewData['domain_name'],
                    hex2bin($oneNewData['pubkey']),
                    $oneNewData['target_address'],
                    $oneNewData['price'],
                    $oneNewData['nonce'],
                );

                // debug logging
                logEvent(
                    'Successfully processed!',
                    'Received valid domain operation command',
                    'indexer.debug'
                );
            }

            // update the latest processed block to the latest processed checkpoint
            setParams([PARAM_LAST_PROCESSED_COIN_BLOCK => $lastCheckpoint['block_id']], $db);

            // Verify the SMT root BEFORE anything is committed.
            //
            // rr-proxy serves reads through the open batch overlay (read-your-own-writes),
            // so this sees the pending tree. Checking after the commits would detect a
            // divergence only once the wrong state was already durable and the
            // last-processed marker had advanced past it — leaving the node to resume on
            // top of state it had itself proven wrong.
            if (($lastSMTRoot = $smt->getRootHex()) !== $lastCheckpoint['smt_root']) {
                throw new RuntimeException(
                    'SMT root verification failed. Expected: ' . $lastCheckpoint['smt_root'] . ', got: ' . $lastSMTRoot,
                    INT_EXC_FATAL
                );
            }

            if ($db->xaTXFinalize(skipCommit: true) === false) {
                throw new RuntimeException('Finalizing DB transaction', INT_EXC_DB);
            }

            $rocksDb->commitTransaction();

            // commiting to MySQL only on successfully commit to the RocksDB
            // if xaTXCommit fails there will be inconsistency which we'll check and fix at the start of synchronization
            if ($db->xaTXCommit() === false) {
                throw new RuntimeException('Commiting DB transaction', INT_EXC_DB);
            }

            logEvent('New SMT root validated: ' . $lastSMTRoot, 'SMT Root', 'indexer.debug');

            $bcLastCheckpointBlockId = $lastCheckpoint['block_id'];
        }
    }
} catch (Throwable $e) {
    logEvent(
        $e->getMessage(),
        match ($e->getCode()) {
            INT_EXC_DB => 'DB Error',
            INT_EXC_FATAL => 'FATAL Error',
            default => 'Exception (' . $e->getCode() . ')',
        },
        'indexer.error'
    );

    // send a notification to the Admin
    if ($e->getCode() === INT_EXC_FATAL &&
        NOTIFY_TYPE === 'telegram' && NOTIFY_TG_BOT_KEY !== '' && NOTIFY_TG_USER_ID !== '') {
        try {
            $telegram = new Api(NOTIFY_TG_BOT_KEY);
            $telegram->sendMessage([
                'chat_id' => NOTIFY_TG_USER_ID,
                'text' => PROJECT_NAME . ' Name Indexer error: ' . $e->getMessage()
            ]);
        } catch (Throwable $e) {
            logEvent(
                $e->getMessage(),
                'Telegram sending error',
                'indexer.debug'
            );
        }
    }
}

// Only ever release a lock this run actually owns. The watchdog command reaches this line
// too when it throws on a stalled lock, and it must not unlink a lock file it never took.
if (LockFileUtils::isOwnLock($watchdogLockFile)) {
    LockFileUtils::releaseLock($watchdogLockFile);
}

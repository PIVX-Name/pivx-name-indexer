<?php declare(strict_types=1);

const PROJECT_NAME = 'PiNS';

if (!is_file($releaseTypeFN = (__DIR__ . '/release_type'))) {
    echo 'release_type is not found in the root of the project';
    exit;
}

$releaseTypeFN
    |> file_get_contents(...)
    |> trim(...)
    |> (static fn($x) => define('RELEASE_TYPE', $x));

if (!is_file($releaseTypeFN = (__DIR__ . '/config/' . RELEASE_TYPE . '.inc.php'))) {
    echo 'release config is not found in ' . $releaseTypeFN;
    exit;
}
require $releaseTypeFN;
unset($releaseTypeFN);

// --- Coin network parameterisation ----------------------------------------------
//
// COIN_NETWORK selects the PIVX network this deployment speaks, and MUST match the
// circuit the prover was built with:
//
//   'mainnet' -> plain `cargo prove build`
//   'testnet' -> `cargo prove build --features testnet`
//
// It is a separate axis from RELEASE_TYPE, which selects a deployment profile: a local
// deployment may legitimately point at either network. Declare it in
// config/<RELEASE_TYPE>.inc.php.
//
// Two things below are derived from it and both are part of the wire protocol - compare
// pins-core's MESSAGE_PREFIX, EXPECTED_HRP and EXPECTED_ADDRESS_LEN. A mismatch means the
// indexer accepts commands the circuit rejects, which stalls every batch.
if (!defined('COIN_NETWORK')) {
    define('COIN_NETWORK', 'mainnet');
}
if (COIN_NETWORK !== 'mainnet' && COIN_NETWORK !== 'testnet') {
    echo 'COIN_NETWORK must be "mainnet" or "testnet", got "' . COIN_NETWORK . '"';
    exit;
}

// Marker at the head of every signed command. Deliberately NOT PROJECT_NAME: that is a
// display name used for log filenames, XA transaction ids and notification text, and must
// stay stable across networks. The wire marker must not - the prefix split is what stops a
// testnet signature being replayed onto mainnet.
const PROTOCOL_MARKER = COIN_NETWORK === 'testnet' ? 't' . PROJECT_NAME : PROJECT_NAME;

if (!defined('WORKLOGS_PATH')) {
    define('WORKLOGS_PATH', dirname(__DIR__, 2) . '/php-worklogs');
}

// --- EVM reorg safety -------------------------------------------------------------
//
// Both halves must refuse to treat an EVM block as settled until the chain says it is.
// The registrar uses this before crediting balances; the indexer uses it to cap the log
// range it reads, so a checkpoint can never be published from a block that later
// vanishes - a resolve() answer citing a root no third party can find on-chain is worse
// than a late one.
//
// Only consulted when the endpoint does not serve the `finalized` tag. Depth is a
// probabilistic substitute for a real finality signal, so it is deliberately generous.
const EVM_FINALITY_FALLBACK_CONFIRMATIONS = 30;

// How long /v1.0/info may reuse the anchored root it last read from the contract.
//
// This is a ceiling, not just a refresh interval: past it the endpoint reports the root as
// unknown rather than serving the last value it happens to hold. Serving an arbitrarily old
// root as what the contract "currently attests to" is the stale-but-confident failure this
// whole measurement exists to avoid - a node reporting itself synced while sitting well
// behind, because both sides of the comparison were frozen at the same value.
const ANCHOR_CACHE_TTL_SEC = 30;

// Internal Exceptions error codes
const INT_EXC_API_ERROR = -97;
const INT_EXC_DB = -98;
const INT_EXC_FATAL = -99;

// How long a held sync lock may live before it is reported as stalled. The lock file is
// created when a run acquires the lock and unlinked when it releases it, so the file's
// mtime is the start time of the run currently holding it.
const SYNC_LOCK_STALE_SEC = 600;

const INDEXER_DB_TABLES = ['checkpoints', 'domains', 'domains_history', 'params', 'marketplace'];

// params
const PARAM_LAST_PROCESSED_COIN_BLOCK = 'last_processed_coin_block';

// API Errors
const API_ERROR_INTERNAL = 'Internal Error';
const API_ERROR_WRONG_API_METHOD = 'Wrong API method';
const API_ERROR_INVALID_INCOMING_JSON = 'Invalid incoming JSON';
const API_ERROR_WRONG_INCOMING_PARAM_VALUE = 'One or several params provided have wrong value';
const API_ERROR_NOT_FOUND = 'Domain not found';

// DEBUG (write debug log)
if (!defined('DEBUG')) {
    define('DEBUG', true);
}

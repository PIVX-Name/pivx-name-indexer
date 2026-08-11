<?php declare(strict_types=1);

use Indexer\Database;
use Indexer\RustRocksDBProxy;
use Indexer\SparseMerkleTree;

/**
 * @var array $uri
 * @var Database $db
 */

if (isset($uri[2])) {
    throw new RuntimeException(API_ERROR_WRONG_API_METHOD, INT_EXC_API_ERROR);
}

// get latest indexer SMT Root
$rocksDb = new RustRocksDBProxy(INDEXER_ROCKSDB_PROXY_SOCKET_PATH);
$smt = new SparseMerkleTree($rocksDb);

apiAnswer('response', $smt->getRootHex());

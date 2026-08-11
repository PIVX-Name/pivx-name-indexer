<?php declare(strict_types=1);

namespace Indexer;

use Throwable;
use Exception;
use Web3\Providers\HttpProvider;
use Web3\Contract;
use Web3\Contracts\Ethabi;
use Web3\Eth;
use ReflectionClass;
use RuntimeException;
use InvalidArgumentException;
use Indexer\Interfaces\Web3EthMethods;
use GuzzleHttp\Exception\TransferException;

class Evm
{
    protected Database $db;
    // chain info
    protected int $chainId;
    protected string $contractAddress;
    // RPC
    protected HttpProvider $httpProvider;
    protected float $rpcTimeout = 30.0;
    // contract
    protected Contract $contract;
    /**
     * @var Eth&Web3EthMethods
     */
    protected Eth $eth;
    protected Ethabi $ethAbi;
    protected array $events;
    /**
     * Block that every contract read is answered from, as a hex quantity, or null to let
     * the node answer from `latest`.
     *
     * Pinned to one concrete height rather than passing the `finalized` tag on each call,
     * for two reasons. Reading `latest` lets a checkpoint that a reorg later discards be
     * persisted, and the reconcile path then treats that as a rollback: it deletes rows
     * and force-stops the node demanding a rebuild, turning an ordinary one-block BSC
     * reorg into an operator-intervention outage. And a run that resolved the tag per call
     * would let the length probe and the fetches that follow it straddle different chain
     * tips, so the walk could observe a length it can no longer read to.
     */
    protected ?string $readBlock = null;
    /**
     * What pinReadBlock() settled on, so it can report the same answer twice.
     *
     * @var array{block: int, mode: string}|null
     */
    protected ?array $pinnedAt = null;


    /**
     * @param array $networkInfo
     * @param Database $db
     * @throws RuntimeException
     */
    public function __construct(array $networkInfo, Database $db)
    {
        $this->db = $db;

        $this->chainId = $networkInfo['chain_id'];
        $this->contractAddress = $networkInfo['contract_address'];

        $this->setRpcUrl();

        $this->contract = new Contract($this->httpProvider, file_get_contents($networkInfo['abi']));
        $this->events = $this->contract->getEvents();
        $this->eth = $this->contract->getEth();
        $this->ethAbi = $this->contract->getEthabi();
    }

    /**
     * @return string
     * @throws RuntimeException
     */
    protected function getRpcUrl(): string
    {
        $qr = $this->db->doSelect(
            'rpc_list',
            '`rpc_url`',
            ['chain_id' => $this->chainId],
            'ORDER BY `issue_ts` ASC LIMIT 1'
        );
        if ($qr === false || $qr->num_rows !== 1) {
            throw new RuntimeException('DB Error: Unable to retrieve rpc URL', INT_EXC_DB);
        }

        return $this->db->fetchAssoc($qr)['rpc_url'];
    }

    /**
     * @param bool $upd
     * @return void
     */
    public function setRpcUrl(bool $upd = false): void
    {
        if (!isset($this->httpProvider)) {
            $this->httpProvider = new HttpProvider($this->getRpcUrl(), $this->rpcTimeout);
            return;
        }

        if ($upd) {
            $qr = $this->db->doUpdate(
                'rpc_list',
                ['issue_ts' => time()],
                [
                    'rpc_url' => $this->httpProvider->getHost(),
                    'chain_id' => $this->chainId
                ]
            );
            if ($qr === false) {
                throw new RuntimeException('DB Error: Unable to update rpc URL issue_ts', INT_EXC_DB);
            }

            $httpProviderRef = new ReflectionClass($this->httpProvider);
            $httpProviderRef->getProperty('host')->setValue($this->httpProvider, $this->getRpcUrl());
            return;
        }

        throw new RuntimeException('Error: httpProvider exists, but method called without upd');
    }

    /**
     * Inspects errors and classifies them as network/availability issues
     * (EvmRpcException) vs logic/parameter issues (passed through).
     *
     * @param mixed $error
     * @throws EvmRpcException
     * @throws Throwable
     */
    protected function handleRpcError(mixed $error): void
    {
        if ($error === null) {
            return;
        }

        $isRpcUnavailable = false;

        if ($error instanceof Throwable) {
            $message = $error->getMessage();
            
            // Catch Guzzle-level connection/timeout/transport exceptions
            if ($error instanceof TransferException) {
                $isRpcUnavailable = true;
            }
            
            // Check previous exception in case it is wrapped
            $prev = $error->getPrevious();
            if ($prev instanceof TransferException) {
                $isRpcUnavailable = true;
            }
        } else {
            $message = (string)$error;
        }

        // Heuristic patterns for nodes/infrastructure failing
        $lowerMsg = strtolower($message);
        $unavailabilityPatterns = [
            'curl error',
            'connection refused',
            'timed out',
            'timeout',
            'cannot resolve',
            'could not resolve',
            'host unreachable',
            'bad gateway',
            'service unavailable',
            'gateway timeout',
            'too many requests',
            'rate limit',
            'status code 429',
            'status code 502',
            'status code 503',
            'status code 504',
            'cloudflare',
            'limit exceeded',
        ];

        foreach ($unavailabilityPatterns as $pattern) {
            if (str_contains($lowerMsg, $pattern)) {
                $isRpcUnavailable = true;
                break;
            }
        }

        if ($isRpcUnavailable) {
            throw new EvmRpcException($message, 0, $error instanceof Throwable ? $error : null);
        }

        if ($error instanceof Throwable) {
            throw $error;
        }
        throw new RuntimeException($message);
    }

    /**
     * Height of the most recent block the chain considers FINAL, or null when the
     * endpoint does not support the tag.
     *
     * BSC finality is provable rather than probabilistic: under Parlia with fast
     * finality a finalized block cannot be reverted without slashing a supermajority
     * of validators. So this is a real guarantee, not a confidence heuristic - which
     * is why anchoring waits on this rather than counting confirmations.
     *
     * Returns null rather than throwing when `finalized` is unsupported: the RPC list
     * is third-party and rotates, so callers fall back to a confirmation depth instead
     * of stalling the pipeline on one endpoint's capabilities.
     *
     * @throws Throwable
     */
    public function getFinalizedBlockNumber(): ?int
    {
        $result = null;
        try {
            $this->eth->getBlockByNumber('finalized', false, function ($err, $data) use (&$result) {
                // Not handleRpcError(): an endpoint that does not know the tag is a
                // capability gap, not a fault worth rotating the endpoint over.
                if ($err !== null || $data === null || !isset($data->number)) {
                    return;
                }
                $result = (int)hexdec(ltrim((string)$data->number, "\x00"));
            });
        } catch (Throwable) {
            return null;
        }

        return $result;
    }

    /**
     * @return int
     * @throws Throwable
     */
    public function getBlockCount(): int
    {
        $result = null;
        try {
            $this->eth->blockNumber(function ($err, $data) use (&$result) {
                if ($err !== null) {
                    $this->handleRpcError($err);
                }
                $result = (int)$data->toString();
            });
        } catch (Throwable $e) {
            $this->handleRpcError($e);
        }

        return $result;
    }

    /**
     * Reads one `view` function on the anchor contract, with throttling applied.
     *
     * All checkpoint discovery goes through here.
     *
     * @param array<int, mixed> $args
     * @return array<int, mixed> raw decoded return values
     * @throws Throwable
     */
    public function callContract(string $function, array $args = []): array
    {
        $out = null;
        $handler = static function ($err, $result) use (&$out, $function) {
            if ($err !== null) {
                if ($err instanceof Throwable) {
                    throw $err;
                }
                throw new RuntimeException('eth_call ' . $function . ': ' . $err);
            }
            $out = $result;
        };

        // Contract::call() pops the callback first and slices off the function's declared
        // inputs; a single remaining non-array argument is then taken as the block to read
        // at. So appending it here needs no change in the library.
        $tail = $this->readBlock === null ? [$handler] : [$this->readBlock, $handler];

        try {
            $this->contract->at($this->contractAddress)->call($function, ...[...$args, ...$tail]);
        } catch (Throwable $e) {
            $this->handleRpcError($e);
        }

        // Paced rather than parallel: the endpoint limit is per-second concurrency, not
        // total volume.
        if (EVM_THROTTLING > 0) {
            usleep(EVM_THROTTLING);
        }

        return $out ?? [];
    }

    /**
     * Pins every subsequent contract read in this run to the newest finalised block, and
     * reports which rule produced it: 'finalized' when the endpoint serves the tag, 'depth'
     * when it does not and a confirmation depth stood in.
     *
     * Those two are not equivalent — depth is probabilistic where the tag is a consensus
     * guarantee — so the caller is expected to log the mode rather than let "final" quietly
     * mean two different things. This mirrors what the registrar already does before it
     * treats an anchor transaction as settled.
     *
     * Idempotent: the first call fixes the height for the lifetime of the instance.
     *
     * @return array{block: int, mode: string}
     * @throws Throwable
     */
    public function pinReadBlock(): array
    {
        // Return what is actually pinned. Re-resolving would report a height the reads are
        // not using, which is worse than not reporting at all.
        if ($this->pinnedAt !== null) {
            return $this->pinnedAt;
        }

        $mode = 'finalized';
        $block = $this->getFinalizedBlockNumber();

        if ($block === null) {
            $mode = 'depth';
            $block = $this->getBlockCount() - EVM_FINALITY_FALLBACK_CONFIRMATIONS;
        }

        // A chain younger than the fallback depth would otherwise ask for a negative
        // height; genesis is the honest floor and simply yields an empty rootChain.
        $block = max($block, 0);

        $this->readBlock = '0x' . dechex($block);

        return $this->pinnedAt = ['block' => $block, 'mode' => $mode];
    }

    /**
     * Normalises a bytes32 the RPC returned into the 64-char lowercase hex this codebase
     * stores.
     *
     * web3.php is inconsistent here and it matters: a non-zero root arrives as the
     * 66-character "0x…" string, but the all-zero genesis root arrives as the single
     * character "0". Storing that verbatim would make the genesis checkpoint compare
     * unequal to the empty-tree root the SMT reports, and the very first verification
     * would fail.
     */
    private static function normaliseRoot(mixed $value): string
    {
        $hex = strtolower((string)$value);
        if (str_starts_with($hex, '0x')) {
            $hex = substr($hex, 2);
        }
        return str_pad($hex, 64, '0', STR_PAD_LEFT);
    }

    /**
     * The root the contract currently attests to.
     *
     * This is what "synced" is measured against — not the newest local checkpoint row,
     * which goes stale in step with the computed root and would agree with it even when
     * the node is far behind.
     *
     * @throws Throwable
     */
    public function getCurrentRoot(): string
    {
        return self::normaliseRoot($this->callContract('currentRoot')[0] ?? '');
    }

    /**
     * Whether `rootChain` has an entry at this index. Out of range reverts.
     *
     * @throws Throwable
     */
    private function rootChainIndexExists(int $index): bool
    {
        try {
            $this->callContract('rootChain', [$index]);
            return true;
        } catch (Throwable $e) {
            // ONLY an actual revert means "past the end". Anything else — a dead
            // endpoint, a rate limit, a timeout — must propagate.
            //
            // Swallowing transport errors here reports an unreachable chain as length 0,
            // which the caller cannot distinguish from "rolled back past genesis". That
            // is not a theoretical concern: it deleted a node's entire checkpoint table
            // during an endpoint outage and left it unable to start.
            if (self::isRevert($e)) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Whether an RPC error is the contract reverting, as opposed to the endpoint failing.
     */
    private static function isRevert(Throwable $e): bool
    {
        return stripos($e->getMessage(), 'execution reverted') !== false
            || stripos($e->getMessage(), 'out of bounds') !== false;
    }

    /**
     * Number of roots the contract has ever accepted (`rootChain.length`).
     *
     * Solidity's auto-generated array getter exposes no length, so this doubles up to find
     * an out-of-range index and then binary-searches the boundary: ~2*log2(n) calls, once
     * per sync run.
     *
     * @throws Throwable
     */
    public function getRootChainLength(): int
    {
        if (!$this->rootChainIndexExists(0)) {
            return 0;
        }

        $lo = 0;
        $hi = 1;
        while ($this->rootChainIndexExists($hi)) {
            $lo = $hi;
            $hi <<= 1;
        }
        while ($lo + 1 < $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($this->rootChainIndexExists($mid)) {
                $lo = $mid;
            } else {
                $hi = $mid;
            }
        }

        return $lo + 1;
    }

    /**
     * The checkpoint at `rootChain[$index]`: its root and the PIVX height it covers.
     *
     * Returns null when the index is past the end, which is how the caller notices the
     * chain shrank under a rollback.
     *
     * @return array{root: string, block_id: int}|null
     * @throws Throwable
     */
    public function getCheckpointAt(int $index): ?array
    {
        try {
            $raw = $this->callContract('rootChain', [$index])[0] ?? null;
        } catch (Throwable $e) {
            // Same rule as rootChainIndexExists(): null means "not on the chain", so only
            // a revert may produce it. An endpoint failure has to surface as an exception.
            if (self::isRevert($e)) {
                return null;
            }
            throw $e;
        }
        if ($raw === null) {
            return null;
        }

        $info = $this->callContract('rootHistory', [$raw]);

        // A valid entry always carries a height. isValid false would mean a rollback
        // repudiated it, in which case it is no longer part of the chain and must not be
        // recorded as a checkpoint.
        if (($info['isValid'] ?? false) !== true) {
            return null;
        }

        return [
            'root' => self::normaliseRoot($raw),
            'block_id' => (int)$info['blockHeight']->toString(),
        ];
    }

    /**
     * @return int
     * @throws Throwable
     */
    public function getIndexBlockHeight(): int
    {
        $this->contract->at($this->contractAddress)->call(
            'currentBlockHeight',
            static function ($err, $result) use (&$indexBlockHeight) {
                if ($err !== null) {
                    if ($err instanceof Throwable) {
                        throw $err;
                    }
                    throw new RuntimeException($err);
                }

                $indexBlockHeight = (int)$result[0]->toString();
            }
        );

        return $indexBlockHeight;
    }
}

class EvmRpcException extends Exception
{
}

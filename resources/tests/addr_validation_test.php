<?php declare(strict_types=1);

// Exercises Protocol::isAddressValid on both networks, against real checksum-valid
// PIVX addresses. Loaded without config.php so COIN_NETWORK can be forced per run.

require __DIR__ . '/bootstrap.php';

use Indexer\Protocol;

$MAIN   = 'ps10ayxgy96kzhzwe0mtd53lraj4hxsu9scyh730vwxna0ul2yxw2pwxvdceu8tajd40fhvkmx3dyz';
$TEST   = 'pts10nwz757gl22x80lupd3vfa3t5s4jhqfq0krgtv0m7gcr5t42eq8ugdww3tpluqjyqlp55z6x39r';
$LEGACY = 'ptestsapling1xnwg2pdn7q82e9uqchyzazut5j7mcef20mg7jkpyk00zry9ep470d3qre69rvwwgcxljydu75dn';
$WALLET = 'ps1f84lvgj0awz2yf2zha5d5gddhd4j88g0kzj5cwp776vhxp5fdjqe7e95cdx2qs5e7fcvg3ds3yg';

$net = COIN_NETWORK;
$ours   = $net === 'testnet' ? $TEST : $MAIN;
$theirs = $net === 'testnet' ? $MAIN : $TEST;

$cases = [
    'this network'                => [$ours, true],
    'other network'               => [$theirs, false],
    'legacy ptestsapling'         => [$LEGACY, false],
    'uppercase of a valid one'    => [strtoupper($ours), false],
    'corrupted checksum'          => [substr($ours, 0, -1) . (substr($ours, -1) === 'q' ? 'p' : 'q'), false],
    'truncated'                   => [substr($ours, 0, -1), false],
    'empty'                       => ['', false],
    'not an address'              => ['not-an-address', false],
    'PIVX transparent'            => ['DAbxRxCXA6vghAMFHmXfmvsLNqCvGjqTvA', false],
    'zcash sapling (wrong coin)'  => ['zs1z7rejlpsa98s2rrrfkwmaxu53e4ue0ulcrw0h4x5g8jl04tak0d3mm47vdtahatqrlkngh9sly', false],
    'configured INCOME_WALLET'    => [$WALLET, $net === 'mainnet'],
];

$pass = 0; $fail = 0;
echo "  --- COIN_NETWORK = $net (marker " . PROTOCOL_MARKER . ") ---\n";
foreach ($cases as $label => [$addr, $want]) {
    $got = Protocol::isAddressValid($addr);
    $ok = $got === $want;
    $ok ? $pass++ : $fail++;
    printf("  %-28s %-6s expected %-5s got %-5s\n", $label, $ok ? 'PASS' : 'FAIL',
        $want ? 'true' : 'false', $got ? 'true' : 'false');
}
echo "  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);

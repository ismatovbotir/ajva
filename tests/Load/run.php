<?php

/*
 * Load / crash test driver. NOT part of the phpunit suite. Pure PHP + ext-curl, no composer deps.
 * Starts nothing: point it at a running copy of the app (see README.md).
 *
 *   php tests/Load/run.php --base=http://127.0.0.1:8081 --pos-token=XXX --cookie="laravel_session=..." \
 *       --scenarios=receipts,items,pages,mixed --levels=5,10,25,50,100 --duration=15
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

$opt = getopt('', [
    'base:', 'pos-token:', 'cookie:', 'scenarios:', 'levels:', 'duration:', 'p95-limit:', 'error-limit:',
    'pos:', 'item-ids:', 'batch-size:', 'item-pool:', 'timeout:', 'out:', 'pages:', 'no-stop', 'help',
]);
if (isset($opt['help']) || ! isset($opt['base'])) {
    exit(<<<'TXT'
Options:
  --base=URL[,URL...]    base URL(s); several are used round-robin (poor man's multi-worker for php -S)
  --pos-token=TOKEN      bearer token for POST /api/receipts (POS_API_TOKEN of the target app)
  --cookie="n=v"         session cookie of a logged-in user (make it with tests/Load/make-cookie.php)
  --scenarios=LIST       receipts,items,pages,mixed        (default: all)
  --levels=LIST          concurrency steps                 (default: 5,10,25,50,100)
  --duration=SEC         seconds per step                  (default: 15)
  --p95-limit=MS         breaking point when p95 exceeds   (default: 3000)
  --error-limit=PCT      breaking point when errors exceed (default: 1)
  --pos=1:1,2:1,3:2      pos_id:shop_id pairs used for receipts (default: 1:1,2:1,3:2,4:2)
  --item-ids=1-12        existing item ids referenced by receipt positions
  --batch-size=200       items per /api/items request (the API limit is 200)
  --item-pool=3000       distinct item ids the items scenario cycles through
  --pages=/,/ordering,/receipts   pages for the pages scenario
  --timeout=30           per-request timeout, seconds
  --out=FILE.json        also write the raw summary as JSON
  --no-stop              do not stop ramping at the breaking point

TXT);
}

$bases = array_map(fn ($b) => rtrim(trim($b), '/'), explode(',', $opt['base']));
$posToken = $opt['pos-token'] ?? '';
$cookie = $opt['cookie'] ?? '';
$scenarios = explode(',', $opt['scenarios'] ?? 'receipts,items,pages,mixed');
$levels = array_map('intval', explode(',', $opt['levels'] ?? '5,10,25,50,100'));
$duration = (float) ($opt['duration'] ?? 15);
$p95Limit = (float) ($opt['p95-limit'] ?? 3000);
$errLimit = (float) ($opt['error-limit'] ?? 1);
$batchSize = (int) ($opt['batch-size'] ?? 200);
$itemPool = (int) ($opt['item-pool'] ?? 3000);
$timeout = (int) ($opt['timeout'] ?? 30);
$pages = explode(',', $opt['pages'] ?? '/,/ordering,/receipts');

$posMap = [];
foreach (explode(',', $opt['pos'] ?? '1:1,2:1,3:2,4:2') as $pair) {
    [$p, $s] = explode(':', $pair);
    $posMap[(int) $p] = (int) $s;
}
$itemIds = [];
foreach (explode(',', $opt['item-ids'] ?? '1-12') as $part) {
    if (str_contains($part, '-')) {
        [$a, $b] = explode('-', $part);
        $itemIds = array_merge($itemIds, range((int) $a, (int) $b));
    } else {
        $itemIds[] = (int) $part;
    }
}

$runId = substr(md5((string) microtime(true)), 0, 8);
$seq = 0;

// ---------------------------------------------------------------- request factories

/** @return array{name:string,method:string,path:string,headers:array,body:?string,ok:int[]} */
function receiptRequest(): array
{
    global $posMap, $itemIds, $posToken, $runId, $seq;
    $posId = array_rand($posMap);
    $shop = $posMap[$posId];
    $lines = [];
    $total = 0;
    for ($i = mt_rand(1, 6); $i > 0; $i--) {
        $qty = mt_rand(1, 4);
        $price = mt_rand(5, 200) * 1000;
        $lines[] = [
            'item' => ['id' => $itemIds[array_rand($itemIds)], 'art' => 'A'.mt_rand(1, 999), 'name' => 'Load item'],
            'labels' => [], 'barcode' => (string) mt_rand(1000000, 9999999),
            'qty' => $qty, 'storno' => 0, 'sum' => $qty * $price, 'sumR' => 0, 'sumWD' => $qty * $price, 'totalSum' => $qty * $price,
        ];
        $total += $qty * $price;
    }
    $refund = mt_rand(1, 100) <= 4;
    $cancel = mt_rand(1, 100) <= 2;
    $body = [
        'shop' => $shop, 'pos' => $posId, 'barcode' => '', 'card' => '', 'openDate' => date('d.m.y'), 'openTime' => date('H:i:s'),
        'closeDate' => '', 'number' => 'L'.$runId.'-'.($seq++).'-'.mt_rand(100, 999),
        'user' => ['id' => mt_rand(1, 5), 'name' => 'Kassir '.mt_rand(1, 5), 'text' => 'Kassir'],
        'payments' => [['name' => mt_rand(0, 1) ? 'cash' : 'card', 'value' => $total]],
        'positions' => $cancel ? [] : $lines,
        'qtyBuys' => count($lines), 'qtyPositions' => count($lines), 'session' => 1,
        'type' => $refund ? 2 : 1, 'status' => $cancel ? 'cancelled' : 'success',
        'sum' => $total, 'sumWithDiscs' => $total, 'total' => $cancel ? 0 : $total, 'aos' => mt_rand(0, 1) ? [] : ['card' => '860000'], 'fiscal' => '',
    ];

    return [
        'name' => 'receipts', 'method' => 'POST', 'path' => '/api/receipts', 'ok' => [202],
        'headers' => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer '.$posToken],
        'body' => json_encode($body),
    ];
}

function itemsRequest(): array
{
    global $batchSize, $itemPool, $posMap;
    $shops = array_values(array_unique($posMap));
    $items = [];
    $start = mt_rand(1, max(1, $itemPool - $batchSize));
    for ($i = 0; $i < $batchSize; $i++) {
        $id = 100000 + $start + $i;   // outside the demo id range; overlapping windows between requests on purpose
        $qty = [];
        $order = [];
        foreach ($shops as $s) {
            $qty[] = ['shop' => ['id' => $s, 'name' => 'Shop '.$s], 'value' => mt_rand(0, 500)];
            $order[] = ['shop' => ['id' => $s, 'name' => 'Shop '.$s], 'min' => 5, 'max' => 50];
        }
        $items[] = [
            'id' => $id, 'group' => ['id' => 1 + $id % 20, 'name' => 'Group '.(1 + $id % 20)], 'mark' => 'LM'.$id,
            'name' => 'Load product '.$id, 'barcode' => ['2'.str_pad((string) $id, 12, '0', STR_PAD_LEFT)],
            'qty' => $qty, 'price' => [['price' => ['id' => 1, 'name' => 'Cost'], 'value' => 1000 + $id % 97], ['price' => ['id' => 2, 'name' => 'Retail'], 'value' => 2000 + $id % 97]],
            'order' => $order, 'class_code' => '012345678978548748', 'package_code' => '1234567',
        ];
    }

    return [
        'name' => 'items', 'method' => 'POST', 'path' => '/api/items', 'ok' => [202],
        'headers' => ['Content-Type: application/json', 'Accept: application/json'],
        'body' => json_encode($items),
    ];
}

function pageRequest(): array
{
    global $pages, $cookie;

    return [
        'name' => 'pages', 'method' => 'GET', 'path' => $pages[array_rand($pages)], 'ok' => [200],
        'headers' => ['Accept: text/html', 'Cookie: '.$cookie], 'body' => null,
    ];
}

function mixedRequest(): array
{
    $r = mt_rand(1, 100);
    if ($r <= 65) {
        return receiptRequest();
    }
    if ($r <= 92) {
        return pageRequest();
    }

    return itemsRequest();
}

// ---------------------------------------------------------------- engine

/**
 * Keep $concurrency requests in flight for $seconds, then drain. Returns raw samples.
 *
 * @return array{samples: array<int, array{0:string,1:bool,2:float,3:int}>, wall: float}
 */
function runStep(callable $factory, int $concurrency, float $seconds): array
{
    global $bases, $timeout;
    $mh = curl_multi_init();
    $inflight = [];
    $samples = [];
    $baseIx = 0;
    $t0 = microtime(true);
    $deadline = $t0 + $seconds;

    $launch = function () use (&$inflight, $mh, $factory, &$baseIx, $bases, $timeout) {
        $req = $factory();
        $ch = curl_init($bases[$baseIx++ % count($bases)].$req['path']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $req['method'],
            CURLOPT_HTTPHEADER => $req['headers'], CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_TCP_NODELAY => true,
        ]);
        if ($req['body'] !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $req['body']);
        }
        curl_multi_add_handle($mh, $ch);
        $inflight[(int) $ch] = [$ch, $req, microtime(true)];
    };

    for ($i = 0; $i < $concurrency; $i++) {
        $launch();
    }

    while ($inflight) {
        curl_multi_exec($mh, $running);
        while ($done = curl_multi_info_read($mh)) {
            $ch = $done['handle'];
            [, $req, $start] = $inflight[(int) $ch];
            $ms = (microtime(true) - $start) * 1000;
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if ($done['result'] !== CURLE_OK) {
                $code = $done['result'] === CURLE_OPERATION_TIMEDOUT ? -1 : 0;   // -1 timeout, 0 connection error
            }
            $samples[] = [$req['name'], in_array($code, $req['ok'], true), $ms, $code];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            unset($inflight[(int) $ch]);
            if (microtime(true) < $deadline) {
                $launch();
            }
        }
        if ($running) {
            curl_multi_select($mh, 0.05);
        }
    }
    curl_multi_close($mh);

    return ['samples' => $samples, 'wall' => microtime(true) - $t0];
}

function pct(array $sorted, float $p): float
{
    if (! $sorted) {
        return 0.0;
    }
    $i = (int) ceil($p / 100 * count($sorted)) - 1;

    return $sorted[max(0, min($i, count($sorted) - 1))];
}

function summarize(array $run): array
{
    $times = [];
    $codes = [];
    $errors = 0;
    foreach ($run['samples'] as [, $isOk, $ms, $code]) {
        $times[] = $ms;
        $codes[$code] = ($codes[$code] ?? 0) + 1;
        $errors += $isOk ? 0 : 1;
    }
    sort($times);
    $n = count($times);
    ksort($codes);

    return [
        'requests' => $n, 'rps' => $n / max(0.001, $run['wall']),
        'p50' => pct($times, 50), 'p95' => pct($times, 95), 'p99' => pct($times, 99), 'max' => $n ? end($times) : 0,
        'error_pct' => $n ? 100 * $errors / $n : 0, 'codes' => $codes, 'wall' => $run['wall'],
    ];
}

function codes(array $c): string
{
    $o = [];
    foreach ($c as $k => $v) {
        $o[] = ($k === -1 ? 'timeout' : ($k === 0 ? 'connerr' : $k)).'x'.$v;
    }

    return implode(' ', $o);
}

// ---------------------------------------------------------------- run

$factories = ['receipts' => 'receiptRequest', 'items' => 'itemsRequest', 'pages' => 'pageRequest', 'mixed' => 'mixedRequest'];
$report = [];
printf("Targets: %s | duration %ss/step | stop when p95 > %dms or errors > %s%%\n", implode(', ', $bases), $duration, $p95Limit, $errLimit);

foreach ($scenarios as $scenario) {
    if (! isset($factories[$scenario])) {
        fwrite(STDERR, "unknown scenario $scenario\n");

        continue;
    }
    if ($scenario === 'pages' && $cookie === '') {
        fwrite(STDERR, "skipping pages: no --cookie\n");

        continue;
    }
    if (in_array($scenario, ['receipts', 'mixed'], true) && $posToken === '') {
        fwrite(STDERR, "skipping $scenario: no --pos-token\n");

        continue;
    }
    printf("\n== %s ==\n%5s %7s %7s %8s %8s %8s %7s  %s\n", $scenario, 'conc', 'reqs', 'rps', 'p50ms', 'p95ms', 'p99ms', 'err%', 'status codes');
    $breaking = null;
    $lastGood = null;
    foreach ($levels as $c) {
        $s = summarize(runStep($factories[$scenario], $c, $duration));
        $report[$scenario][$c] = $s;
        printf("%5d %7d %7.1f %8.0f %8.0f %8.0f %7.2f  %s\n", $c, $s['requests'], $s['rps'], $s['p50'], $s['p95'], $s['p99'], $s['error_pct'], codes($s['codes']));
        if ($s['error_pct'] > $errLimit || $s['p95'] > $p95Limit) {
            $breaking ??= ['concurrency' => $c, 'reason' => $s['error_pct'] > $errLimit ? sprintf('errors %.1f%%', $s['error_pct']) : sprintf('p95 %.0fms', $s['p95'])];
            if (! isset($opt['no-stop'])) {
                break;
            }
        } else {
            $lastGood = $c;
        }
        sleep(2);   // let the server breathe between steps
    }
    $report[$scenario]['_breaking'] = $breaking;
    $report[$scenario]['_last_good'] = $lastGood;
    echo $breaking
        ? sprintf("-> breaking point: %d concurrent (%s); last good level: %s\n", $breaking['concurrency'], $breaking['reason'], $lastGood ?? 'none')
        : sprintf("-> no breaking point up to %d concurrent\n", end($levels));
}

if (isset($opt['out'])) {
    file_put_contents($opt['out'], json_encode($report, JSON_PRETTY_PRINT));
}

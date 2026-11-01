<?php
declare(strict_types=1);

// Dependency-free test runner: `php tests/run.php` (exit 1 on any failure).
require __DIR__ . '/../src/Evaluator.php';
foreach (['OpsV2', 'FlagSnapshot', 'FlagDefinitionCacheProvider', 'InMemoryFlagDefinitionCache', 'FlagCache', 'LucidaException', 'Context', 'Sink', 'Delivery', 'FlagApi', 'Identity', 'Client', 'Lucida'] as $f) {
    require __DIR__ . "/../src/$f.php";
}

use Lucida\Client;
use Lucida\Evaluator;

$fails = 0;
$checks = 0;
function check(bool $ok, string $msg): void
{
    global $fails, $checks;
    $checks++;
    if (!$ok) {
        $fails++;
        fwrite(STDERR, "FAIL $msg\n");
    }
}
function load(string $p): array
{
    return json_decode(file_get_contents((getenv('LUCIDA_TESTDATA') ?: __DIR__ . '/../../../testdata') . '/' . $p), true, 512, JSON_THROW_ON_ERROR);
}

const CTX = [
    'distinct_id' => ['distinct_id', 'bucketing_key'], 'groups' => ['groups'], 'person_properties' => ['person_properties'],
    'group_properties' => ['group_properties'], 'device_properties' => ['device_properties'],
    'geo_properties' => ['geo_properties'], 'app_version' => ['app_version'],
];

function supplied(array $v): array
{
    if (!isset($v['needs'])) {
        return $v['context'];
    }
    $keep = [];
    foreach ($v['needs'] as $n) {
        $keep = array_merge($keep, CTX[$n] ?? []);
    }
    return array_intersect_key($v['context'], array_flip($keep));
}

// conformance vectors
$total = 0;
foreach (['vectors_basic.json', 'vectors_g10.json', 'vectors_sticky.json', 'vectors_ops.json', 'vectors.json'] as $name) {
    $d = load("flags/$name");
    foreach ($d['hash_vectors'] as $h) {
        check(Evaluator::hash01($h['input']) === (float) $h['hash'], "hash {$h['input']}");
    }
    foreach ($d['vectors'] as $v) {
        $r = Evaluator::evaluateAll($d['flags'], supplied($v))[$v['flag']];
        $e = $v['expected'];
        $ok = $r['value'] === $e['value'] && $r['variant'] === ($e['variant'] ?? null) && $r['reason'] === $e['reason']
            && $r['condition_index'] === $e['condition_index'] && $r['payload'] == ($e['payload'] ?? null);
        check($ok, "{$v['name']}: " . json_encode($r) . ' != ' . json_encode($e));
        $total++;
    }
}
check($total > 10000, "vector count $total");

// wire fixtures
foreach (load('events/server_sdk.json')['cases'] as $c) {
    $sent = [];
    $lc = new Client('lc_pk_x', transport: function (string $u, string $b) use (&$sent): int {
        $sent[] = [$u, $b];
        return 200;
    });
    $k = $c['call'];
    switch ($k['method']) {
        case 'capture':
            $lc->capture($k['distinct_id'], $k['event'], $k['properties'] ?? [], $k['groups'] ?? null, $k['timestamp'] ?? null, $k['uuid'] ?? null, $k['set'] ?? null, $k['set_once'] ?? null);
            break;
        case 'identify':
            $lc->identify($k['distinct_id'], $k['properties'] ?? null, $k['properties_set_once'] ?? null);
            break;
        case 'alias':
            $lc->alias($k['distinct_id'], $k['alias']);
            break;
        default:
            $lc->groupIdentify($k['group_type'], $k['group_key'], $k['properties'] ?? [], $k['distinct_id'] ?? null);
    }
    $lc->flush();
    $body = json_decode($sent[0][1], true);
    check($body['api_key'] === 'lc_pk_x' && count($body['batch']) === 1, "{$c['name']}: envelope");
    $ev = $body['batch'][0];
    check(str_starts_with($ev['properties']['$lib'] ?? '', 'lucida-') && !empty($ev['properties']['$lib_version']), "{$c['name']}: lib props");
    unset($ev['properties']['$lib'], $ev['properties']['$lib_version']);
    $want = $c['expected'];
    foreach (['uuid', 'timestamp'] as $f) {
        if ($want[$f] === '*') {
            check($ev[$f] !== '', "{$c['name']}: $f");
            $want[$f] = $ev[$f];
        }
    }
    check($ev == $want, "{$c['name']}: " . json_encode($ev) . ' != ' . json_encode($want));
}

// batching, bounded queue, retry, disabled, exposure dedupe
$sent = [];
$lc = new Client('k', flushAt: 3, maxQueueSize: 5, transport: function (string $u, string $b) use (&$sent): int {
    $sent[] = count(json_decode($b, true)['batch']);
    return 200;
});
for ($i = 0; $i < 7; $i++) {
    $lc->capture('u', "e$i");
}
check($sent === [3, 3], 'batches at flushAt: ' . json_encode($sent));
$lc->flush();
check(array_sum($sent) === 7, 'all delivered');

$ok = false;
$lc = new Client('k', transport: function () use (&$ok): int {
    return $ok ? 200 : 503;
}, sleeper: function (): void {
});
$lc->capture('u', 'e');
check($lc->flush() === false, 'failed batch reports false');
$ok = true;
check($lc->flush() === true, 'kept batch is retried');

$sent = [];
$lc = new Client('k', disabled: true, transport: function () use (&$sent): int {
    $sent[] = 1;
    return 200;
});
$lc->capture('u', 'e');
$lc->flush();
check($sent === [] && $lc->getFeatureFlag('x', 'u') === null, 'disabled sends nothing');

$names = [];
$lc = new Client('k', transport: function (string $u, string $b) use (&$names): int {
    foreach (json_decode($b, true)['batch'] as $e) {
        $names[] = $e['event'];
    }
    return 200;
});
$defs = load('flags/vectors_basic.json')['flags'];
$lc->setDefinitions($defs);
$lc->getFeatureFlag($defs[0]['key'], 'user-1');
$lc->getFeatureFlag($defs[0]['key'], 'user-1');
$lc->flush();
check(count(array_filter($names, fn ($n) => $n === '$exposure')) === 1, 'exposure deduped');

require __DIR__ . '/delivery.php';
require __DIR__ . '/flags_depth.php';
require __DIR__ . '/identity.php';

echo ($fails ? "FAILED $fails of $checks checks\n" : "ok $checks checks\n");
exit($fails ? 1 : 0);

<?php
declare(strict_types=1);

// Delivery, flag fallback, context, personless, import and test-mode checks (required by run.php, shares check()).

use Lucida\Client;
use Lucida\Context;
use Lucida\LucidaException;

function body(string $b): array
{
    return json_decode(str_starts_with($b, '{') ? $b : gzdecode($b), true);
}

/** @return array{0: Client, 1: object} client + recorder with calls[] (url, body, headers) and sleeps[] */
function rig(array $statuses = [], array $opts = []): array
{
    $r = new stdClass();
    $r->calls = [];
    $r->sleeps = [];
    $lc = new Client('lc_pk_x', ...$opts, transport: function (string $u, ?string $b, array $h) use ($r, &$statuses) {
        $r->calls[] = [$u, $b, $h];
        return $statuses ? array_shift($statuses) : 200;
    }, sleeper: function (float $d, ?float $ra) use ($r): void {
        $r->sleeps[] = [$d, $ra];
    });
    $r->sent = fn (): array => array_merge(...array_map(fn ($c) => $c[1] !== null ? (body($c[1])['batch'] ?? []) : [], $r->calls ?: [[0, null]]));
    return [$lc, $r];
}

// retry with backoff, Retry-After passed to the sleeper
[$lc, $r] = rig([[429, 7.0], 503, 200]);
$lc->capture('u', 'e');
check($lc->flush() === true && count($r->calls) === 3, 'retries until success');
check($r->sleeps[0][1] === 7.0 && $r->sleeps[1][1] === null && $r->sleeps[1][0] <= 1.0, 'backoff + retry-after handed to sleeper');

// gives up, keeps the batch, reports through onError
$errs = [];
[$lc, $r] = rig(array_fill(0, 10, 503), ['maxRetries' => 2, 'onError' => function (LucidaException $e) use (&$errs): void {
    $errs[] = $e;
}]);
$lc->capture('u', 'e');
check($lc->flush() === false && count($r->calls) === 3 && $errs && $errs[0]->status === 503, 'gives up after maxRetries and reports');

// permanent 4xx drops without retry
$lc = null; // old clients flush in their destructor
$errs = [];
[$lc, $r] = rig([400], ['onError' => function (LucidaException $e) use (&$errs): void {
    $errs[] = $e;
}]);
$lc->capture('u', 'e');
check($lc->flush() === true && count($r->calls) === 1 && str_contains($errs[0]->getMessage(), 'dropped'), '400 dropped, no retry');
check($lc->flush() === true && count($r->calls) === 1, 'dropped batch is gone');

// a throwing transport counts as status 0 (retryable)
$lc = new Client('k', transport: function (): int {
    throw new RuntimeException('down');
}, maxRetries: 1, sleeper: function (): void {
});
$lc->capture('u', 'e');
check($lc->flush() === false, 'transport exception is a failed delivery');

// beforeSend: drop / modify / throw keeps original
[$lc, $r] = rig([], ['beforeSend' => function (array $e): ?array {
    return match ($e['event']) {
        'drop' => null,
        'boom' => throw new RuntimeException('x'),
        default => ['event' => 'renamed'] + $e,
    };
}]);
foreach (['drop', 'boom', 'ok'] as $n) {
    $lc->capture('u', $n);
}
$lc->flush();
check(array_column(($r->sent)(), 'event') === ['boom', 'renamed'], 'beforeSend drop/modify/throw');

// maxBatchSize is separate from flushAt
[$lc, $r] = rig([], ['flushAt' => 100, 'maxBatchSize' => 2]);
for ($i = 0; $i < 5; $i++) {
    $lc->capture('u', "e$i");
}
$lc->flush();
check(array_map(fn ($c) => count(body($c[1])['batch']), $r->calls) === [2, 2, 1], 'maxBatchSize chunks');

// interval flush on capture (fake clock)
$t = 1000.0;
[$lc, $r] = rig([], ['flushInterval' => 5.0, 'clock' => function () use (&$t): float {
    return $t;
}]);
$lc->capture('u', 'a');
check(count($r->calls) === 0, 'no flush before interval');
$t += 6;
$lc->capture('u', 'b');
check(count($r->calls) === 1 && count(body($r->calls[0][1])['batch']) === 2, 'flush when interval elapsed');

// context: layering, explicit wins, fresh, headers
[$lc, $r] = rig();
Client::withContext(['distinct_id' => 'ctx', 'session_id' => 's1', 'properties' => ['a' => 1]], function () use ($lc): void {
    Client::withContext(['properties' => ['b' => 2]], function () use ($lc): void {
        $lc->capture(null, 'x', ['a' => 9]);
        $lc->capture('explicit', 'y');
    });
    Client::withContext(['fresh' => true, 'distinct_id' => 'f'], fn () => $lc->capture(null, 'z'));
});
$lc->capture(null, 'dropped');
$lc->flush();
[$x, $y, $z] = ($r->sent)();
check($x['distinct_id'] === 'ctx' && $x['properties']['a'] === 9 && $x['properties']['b'] === 2 && $x['properties']['$session_id'] === 's1', 'context merged, explicit props win');
check($y['distinct_id'] === 'explicit' && !isset($z['properties']['$session_id']) && count(($r->sent)()) === 3, 'explicit id wins, fresh ignores parent, no id drops');
check(Client::getContext()['distinct_id'] === null, 'context popped');
try {
    Client::withContext(['distinct_id' => 'q'], function (): never {
        throw new RuntimeException('boom');
    });
} catch (RuntimeException) {
}
check(Client::getContext()['distinct_id'] === null, 'context popped after throw');
$h = Client::contextFromHeaders(['X-Lucida-Distinct-Id' => " u\x01 1 ", 'HTTP_X_LUCIDA_SESSION_ID' => '']);
check($h === ['distinct_id' => 'u 1', 'session_id' => null], 'headers cleaned');
check(strlen(Client::contextFromHeaders(['x-lucida-session-id' => [str_repeat('s', 2000)]])['session_id']) === 1000, 'header capped at 1000');

// personless
[$lc, $r] = rig();
$lc->capture(null, 'p', processPersonProfile: false);
$lc->capture('u', 'q', processPersonProfile: false);
$lc->flush();
[$a, $b] = ($r->sent)();
check(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/', $a['distinct_id']) === 1 && $a['properties']['$process_person_profile'] === false && $b['distinct_id'] === 'u', 'personless');

// import
[$lc, $r] = rig([], ['secretKey' => 'lc_sk_x']);
$n = $lc->importEvents(array_map(fn ($i) => ['distinct_id' => 'u', 'event' => 'old', 'timestamp' => '2020-01-01T00:00:00Z', 'uuid' => "id$i"], range(1, 1500)));
check($n === 1500 && count($r->calls) === 2 && str_contains($r->calls[0][0], '/v1/s2s/batch') && in_array('Authorization: Bearer lc_sk_x', $r->calls[0][2], true), 'import chunks with secret key');
check(($r->sent)()[0]['timestamp'] === '2020-01-01T00:00:00Z', 'import keeps timestamps');
[$noSecret] = rig();
try {
    $noSecret->importEvents([]);
    check(false, 'import needs secret');
} catch (LucidaException) {
    check(true, 'import needs secret');
}
[$lc] = rig([500, 500, 500, 500, 500], ['secretKey' => 'k']);
try {
    $lc->importEvents([['distinct_id' => 'u', 'event' => 'e']]);
    check(false, 'import failure throws');
} catch (LucidaException $e) {
    check($e->status === 500, 'import failure throws');
}

// remote fallback
$defs = [
    ['key' => 'local', 'active' => true, 'type' => 'boolean', 'salt' => 's', 'conditions' => [['rollout_pct' => 100]]],
    ['key' => 'cohorty', 'active' => true, 'type' => 'boolean', 'salt' => 's',
        'conditions' => [['filters' => [['type' => 'cohort', 'key' => 'c1', 'op' => 'in']], 'rollout_pct' => 100]]],
];
$remote = json_encode(['request_id' => 'r1', 'flags' => ['cohorty' => ['enabled' => true, 'value' => 'v1', 'payload' => ['x' => 1], 'reason' => 'cohort']]]);
$mk = function (array $opts = []) use ($defs, $remote, &$posts): Client {
    $posts = [];
    $lc = new Client('pk', ...$opts, transport: function (string $u, ?string $b) use ($remote, &$posts) {
        $posts[] = [$u, $b];
        return str_ends_with($u, '/v1/flags') ? [200, null, $remote] : 200;
    });
    $lc->setDefinitions($defs);
    return $lc;
};
$lc = $mk();
$res = $lc->getFeatureFlagResult('cohorty', 'u');
check($res['value'] === 'v1' && $res['payload'] === ['x' => 1] && $res['reason'] === 'cohort' && $res['locally_evaluated'] === false && $res['request_id'] === 'r1', 'remote fallback result');
$sent = json_decode($posts[0][1], true);
check($sent['distinct_id'] === 'u' && $sent['flag_keys'] === ['cohorty'], 'remote body');
$lc2 = $mk();
\Lucida\Context::with(['anon_distinct_id' => 'anon-9'], fn () => $lc2->getFeatureFlagResult('cohorty', 'u'));
check(json_decode($posts[0][1], true)['anon_distinct_id'] === 'anon-9' && !isset($sent['anon_distinct_id']), 'anon_distinct_id travels from the context only');
check($lc->getFeatureFlag('local', 'u') === true && count(array_filter($posts, fn ($p) => str_ends_with($p[0], '/v1/flags'))) === 1, 'local flag needs no remote call');
$lc = null;
$lc = $mk(['onlyEvaluateLocally' => true]);
check($lc->getFeatureFlag('cohorty', 'u') === null && $posts === [], 'only_evaluate_locally option');
check($mk()->getFeatureFlag('cohorty', 'u', onlyEvaluateLocally: true) === null, 'only_evaluate_locally per call');
$lc = $mk();
check($lc->getAllFlags('u') === ['local' => true, 'cohorty' => 'v1'], 'getAllFlags merges remote');
check($lc->getAllFlagsAndPayloads('u')['payloads'] === ['cohorty' => ['x' => 1]], 'payloads only where present');
check($lc->getFeatureFlagPayload('cohorty', 'u') === ['x' => 1], 'payload accessor');
check($mk(['onlyEvaluateLocally' => true])->getAllFlags('u') === ['local' => true], 'getAllFlags local only');
$lc = new Client('pk', transport: fn () => 500, maxRetries: 0);
$lc->setDefinitions($defs);
check($lc->getFeatureFlag('cohorty', 'u') === null, 'remote failure is null');

// poll on access, ETag reuse
$t = 0.0;
$etags = [];
$lc = new Client('pk', secretKey: 'sk', flagPollInterval: 30.0, clock: function () use (&$t): float {
    return $t;
}, transport: function (string $u, ?string $b, array $h) use (&$etags, $defs) {
    if ($b === null) {
        $etags[] = array_values(array_filter($h, fn ($x) => str_starts_with($x, 'If-None-Match')));
        return $etags[count($etags) - 1] ? 304 : [200, null, json_encode(['flags' => $defs]), '"e1"'];
    }
    return 200;
});
check($lc->getFeatureFlag('local', 'u', onlyEvaluateLocally: true, sendExposure: false) === true && count($etags) === 1, 'first access polls');
$lc->getFeatureFlag('local', 'u', onlyEvaluateLocally: true, sendExposure: false);
check(count($etags) === 1, 'no poll within interval');
$t = 31.0;
$lc->getFeatureFlag('local', 'u', onlyEvaluateLocally: true, sendExposure: false);
check(count($etags) === 2 && $etags[1] === ['If-None-Match: "e1"'], 'stale poll reuses etag');

// test mode
$lc = new Client('k', testMode: ['flags' => [$defs[0]]]);
$lc->capture('u', 'e');
check($lc->getFeatureFlag('local', 'u') === true && $lc->getFeatureFlag('missing', 'u') === null, 'testMode local flags');
check(array_column($lc->sink->events, 'event') === ['e', '$feature_flag_called', '$exposure'], 'testMode sink');
$lc->sink->clear();
check($lc->sink->events === [], 'sink clear');
$tmp = tempnam(sys_get_temp_dir(), 'lc');
file_put_contents($tmp, json_encode(['flags' => []]));
check((new Client('k', testMode: $tmp))->getAllFlags('u') === [], 'testMode fixture file');
unlink($tmp);
try {
    new Client('k', testMode: ['nope' => 1]);
    check(false, 'bad fixture rejected');
} catch (InvalidArgumentException) {
    check(true, 'bad fixture rejected');
}

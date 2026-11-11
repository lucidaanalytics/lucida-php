<?php
declare(strict_types=1);

// Included by run.php: super properties, person properties, identify/group dedupe, global facade.

$sent = [];
$mk = function () use (&$sent): Lucida\Client {
    $sent = [];
    return new Lucida\Client('k', transport: function (string $u, ?string $b) use (&$sent): int {
        foreach (json_decode(str_starts_with((string) $b, "\x1f\x8b") ? gzdecode($b) : (string) $b, true)['batch'] as $e) {
            $sent[] = $e;
        }
        return 200;
    });
};

$lc = $mk();
$lc->register(['app' => 'a', 'env' => 'prod']);
$lc->unregister('env');
$lc->capture('u', 'e1');
$lc->capture('u', 'e2', ['app' => 'mine']);
$lc->setPersonProperties('u', ['plan' => 'pro']);
$lc->setPersonPropertiesOnce('u', ['first' => 'x']);
for ($i = 0; $i < 3; $i++) {
    $lc->identify('u', ['a' => 1]);
    $lc->groupIdentify('org', 'acme', ['n' => 1]);
}
$lc->identify('u', ['a' => 2]);
$lc->flush();
check($sent[0]['properties']['app'] === 'a' && !isset($sent[0]['properties']['env']), 'super props, unregister');
check($sent[1]['properties']['app'] === 'mine', 'per-call props win over super');
check($sent[2]['event'] === '$set' && $sent[2]['$set'] === ['plan' => 'pro'], 'setPersonProperties');
check($sent[3]['$set_once'] === ['first' => 'x'], 'setPersonPropertiesOnce');
check(array_column(array_slice($sent, 4), 'event') === ['$identify', '$groupidentify', '$identify'], 'identify/group dedupe');

// a dropped batch frees the identity: the identical call is sent again
$calls = 0;
$got = [];
$lc = new Lucida\Client('k', transport: function (string $u, ?string $b) use (&$calls, &$got): int {
    foreach (json_decode((string) $b, true)['batch'] as $e) {
        $got[] = $e['event'];
    }
    return ++$calls === 1 ? 400 : 200;
});
$lc->identify('u', ['a' => 1]);
$lc->groupIdentify('org', 'acme', ['n' => 1]);
$lc->flush();
$lc->identify('u', ['a' => 1]);
$lc->groupIdentify('org', 'acme', ['n' => 1]);
$lc->flush();
check($got === ['$identify', '$groupidentify', '$identify', '$groupidentify'], 'identify resent after its batch was dropped');

// importEvents applies defaults and beforeSend unless raw
$imp = [];
$lc = new Lucida\Client('k', secretKey: 'sk', beforeSend: fn (array $e): ?array => $e['event'] === 'drop' ? null : $e, transport: function (string $u, ?string $b) use (&$imp): int {
    foreach (json_decode((string) $b, true)['batch'] as $e) {
        $imp[] = $e;
    }
    return 200;
});
$lc->register(['env' => 'prod']);
$n1 = $lc->importEvents([['distinct_id' => 'u', 'event' => 'keep'], ['distinct_id' => 'u', 'event' => 'drop']]);
$n2 = $lc->importEvents([['distinct_id' => 'u', 'event' => 'plain']], raw: true);
check($n1 === 1 && $n2 === 1 && ($imp[0]['properties']['env'] ?? null) === 'prod' && !isset($imp[1]['properties']['env']), 'importEvents defaults + beforeSend unless raw');

$sent = [];
Lucida\Lucida::init('k', ['transport' => function (string $u, ?string $b) use (&$sent): int {
    foreach (json_decode(str_starts_with((string) $b, "\x1f\x8b") ? gzdecode($b) : (string) $b, true)['batch'] as $e) {
        $sent[] = $e;
    }
    return 200;
}]);
Lucida\Lucida::capture('u', 'via_facade', ['x' => 1]);
check(Lucida\Lucida::flush() && count($sent) === 1 && $sent[0]['event'] === 'via_facade', 'global facade captures');
Lucida\Lucida::shutdown();
Lucida\Lucida::capture('u', 'ignored');
check(Lucida\Lucida::client() === null && Lucida\Lucida::flush() === false, 'facade is inert after shutdown');

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

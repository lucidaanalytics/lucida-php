<?php
declare(strict_types=1);

// Snapshot / event enrichment / definition cache / remote-config payload (LC-FLG-28, 29, 45, 46). Required by run.php.
use Lucida\Client;
use Lucida\FlagCache;
use Lucida\FlagSnapshot;
use Lucida\InMemoryFlagDefinitionCache;

$on = fn (string $key, array $ctxs = [], int $pct = 100): array => [
    'key' => $key, 'type' => 'boolean', 'active' => true, 'salt' => 's', 'aggregation' => ['kind' => 'person'],
    'app_contexts' => $ctxs, 'conditions' => [['rollout_pct' => $pct]],
];
$flags = [$on('a'), $on('b'), $on('off', [], 0), $on('web', ['web'])];

$lc = new Client('lc_pk_x', secretKey: 'lc_sk_x', testMode: $flags, appContexts: ['api']);
$lc->capture('u', 'buy', ['$feature/a' => 'mine'], sendFeatureFlags: true);
$snap = $lc->evaluateFlags('u');
check($snap->isEnabled('a') && $snap->keys() === ['a', 'b', 'off'], 'snapshot keys drop app-context-excluded flags');
$lc->capture('u', 'e1', flags: $snap->onlyAccessed());
$p0 = $lc->sink->events[0]['properties'];
$p1 = $lc->sink->events[1]['properties'];
check($p0['$feature/a'] === 'mine' && $p0['$feature/off'] === false && !isset($p0['$feature/web']), 'enrichment, caller wins');
check($p0['$active_feature_flags'] === ['a', 'b'], 'active_feature_flags');
check($p1['$feature/a'] === true && !isset($p1['$feature/b']), 'only_accessed snapshot');
check(count(array_filter($lc->sink->events, fn ($e) => $e['event'] === '$feature_flag_called')) === 0, 'snapshot emits no exposure');
check(FlagSnapshot::eventProps(['x' => 1], []) === ['x' => 1], 'empty values leave props alone');

// definition cache provider
$cache = new InMemoryFlagDefinitionCache(60.0);
$cache->onFlagDefinitionsReceived($flags);
$calls = 0;
$lc = new Client('k', secretKey: 's', flagPollInterval: 0, flagDefinitionCache: $cache, transport: function () use (&$calls): int {
    $calls++;
    return 500;
});
check($lc->reloadFeatureFlags() && $calls === 0, 'fresh cache is adopted without a fetch');
check($lc->getFeatureFlag('a', 'u', onlyEvaluateLocally: true) === true, 'adopted definitions evaluate');

$fresh = new InMemoryFlagDefinitionCache(60.0);
$body = json_encode(['flags' => [$flags[0]]]);
$lc = new Client('k', secretKey: 's', flagPollInterval: 0, flagDefinitionCache: $fresh, transport: fn () => [200, null, $body, null]);
check($lc->reloadFeatureFlags() && count($fresh->flagDefinitions() ?? []) === 1, 'fetched definitions are published');
$broken = new class () implements Lucida\FlagDefinitionCacheProvider {
    public function flagDefinitions(): ?array { throw new RuntimeException('down'); }
    public function shouldFetchFlagDefinitions(): bool { throw new RuntimeException('down'); }
    public function onFlagDefinitionsReceived(array $definitions): void { throw new RuntimeException('down'); }
    public function shutdown(): void {}
};
check(FlagCache::adopt($broken) === null, 'broken cache means fetch');

// remote config payload
$sent = null;
$resp = json_encode(['key' => 'a/b', 'enabled' => true, 'payload' => ['x' => 1]]);
$lc = new Client('k', secretKey: 'sk', flagPollInterval: 0, transport: function (string $u, ?string $b, array $h) use (&$sent, $resp): array {
    $sent = [$u, $b, $h];
    return str_ends_with($u, '/v1/flags/a%2Fb/remote-config') ? [200, null, $resp, null] : [404, null, '', null];
});
check($lc->getRemoteConfigPayload('a/b') === ['x' => 1], 'remote config payload');
check($sent[1] === null && in_array('Authorization: Bearer sk', $sent[2], true), 'remote config GETs with the secret key');
check($lc->getRemoteConfigPayload('nope') === null, 'remote config 404 is null');
$threw = false;
try {
    (new Client('k'))->getRemoteConfigPayload('cfg');
} catch (Lucida\LucidaException) {
    $threw = true;
}
check($threw, 'remote config needs a secret key');

// fallbacks (N-EXP-16)
$lc = new Client('k', secretKey: 's', testMode: $flags, fallbacks: ['ghost' => 'safe', 'a' => false]);
$d = $lc->getFeatureFlagDetails('ghost', 'u');
check($d !== null && $d['value'] === 'safe' && $d['reason'] === 'fallback', 'missing flag serves the fallback');
$d = $lc->getFeatureFlagDetails('a', 'u');
check($d['value'] === true && $d['reason'] === 'condition_match' && $d['condition_index'] === 0, 'served flag keeps its real reason');
check($lc->getFeatureFlag('nope', 'u') === null, 'no fallback keeps the old behaviour');
$lc->sink->clear();
$lc->getFeatureFlag('ghost', 'u');
check($lc->sink->events === [], 'fallback records no exposure');
$lc = new Client('k', flagPollInterval: 0, fallbacks: ['x' => 'down'], transport: fn () => 503, sleeper: function (): void {});
check($lc->getFeatureFlagDetails('x', 'u')['reason'] === 'fallback', 'network failure serves the fallback');

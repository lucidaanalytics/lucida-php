<?php
declare(strict_types=1);

namespace Lucida;

/**
 * Flag evaluation for Client: local definitions (re-polled on access when older than flagPollInterval, ETag aware)
 * with a remote /v1/flags fallback for flags the definitions cannot decide, unless onlyEvaluateLocally.
 * A flag result is ['key', 'value', 'payload', 'reason', 'locally_evaluated', 'request_id'].
 */
trait FlagApi
{
    /** Fetches definitions (needs a secret key). Keeps the previous ones on any failure. */
    public function reloadFeatureFlags(): bool
    {
        if ($this->secretKey === null || $this->disabled) {
            return false;
        }
        $this->lastPoll = $this->now();
        $cached = FlagCache::adopt($this->flagDefinitionCache);
        if ($cached !== null) {
            $this->defs = $cached;
            return true;
        }
        $h = ['Authorization: Bearer ' . $this->secretKey];
        if ($this->etag !== null) {
            $h[] = 'If-None-Match: ' . $this->etag;
        }
        [$status, , $body, $etag] = $this->request($this->host . '/v1/flags/definitions', null, $h);
        if ($status === 304) {
            return true;
        }
        $flags = is_string($body) && $status >= 200 && $status < 300 ? (json_decode($body, true)['flags'] ?? null) : null;
        if (!is_array($flags)) {
            return false;
        }
        $this->defs = $flags;
        $this->etag = $etag;
        FlagCache::publish($this->flagDefinitionCache, $flags);
        return true;
    }

    /** @param array[] $flags definitions to use without fetching (bootstrap / tests) */
    public function setDefinitions(array $flags): void
    {
        $this->defs = $flags;
        $this->lastPoll = $this->now();
    }

    /** Full result (value, payload, reason) or null when unknown / undecidable. Records exposure unless $sendExposure is false. */
    public function getFeatureFlagResult(
        string $key,
        ?string $distinctId = null,
        array $personProperties = [],
        array $groups = [],
        array $groupProperties = [],
        bool $sendExposure = true,
        ?bool $onlyEvaluateLocally = null,
    ): ?array {
        $id = $distinctId ?? Context::current()['distinct_id'];
        if ($this->disabled || $id === null) {
            return null;
        }
        $r = $this->resolve($key, $id, [$personProperties, $groups, $groupProperties], $onlyEvaluateLocally ?? $this->onlyEvaluateLocally);
        if ($r !== null && $sendExposure) {
            $this->expose($id, $r);
        }
        return $r;
    }

    /** False, true or a variant key; null when unknown or undecidable. */
    public function getFeatureFlag(
        string $key,
        ?string $distinctId = null,
        array $personProperties = [],
        array $groups = [],
        array $groupProperties = [],
        bool $sendExposure = true,
        ?bool $onlyEvaluateLocally = null,
    ): bool|string|null {
        return $this->getFeatureFlagResult($key, $distinctId, $personProperties, $groups, $groupProperties, $sendExposure, $onlyEvaluateLocally)['value'] ?? null;
    }

    public function isFeatureEnabled(string $key, ?string $distinctId = null, array $personProperties = [], array $groups = []): ?bool
    {
        $v = $this->getFeatureFlag($key, $distinctId, $personProperties, $groups);
        return $v === null ? null : $v !== false;
    }

    public function getFeatureFlagPayload(
        string $key,
        ?string $distinctId = null,
        array $personProperties = [],
        array $groups = [],
        array $groupProperties = [],
        ?bool $onlyEvaluateLocally = null,
    ): mixed {
        return $this->getFeatureFlagResult($key, $distinctId, $personProperties, $groups, $groupProperties, false, $onlyEvaluateLocally)['payload'] ?? null;
    }

    /** @return array<string, bool|string> every flag decidable locally (plus remote ones unless onlyEvaluateLocally); no exposure events */
    public function getAllFlags(
        ?string $distinctId = null,
        array $personProperties = [],
        array $groups = [],
        array $groupProperties = [],
        ?bool $onlyEvaluateLocally = null,
    ): array {
        return array_map(fn (array $r) => $r['value'], $this->allResults($distinctId, [$personProperties, $groups, $groupProperties], $onlyEvaluateLocally ?? $this->onlyEvaluateLocally));
    }

    /** @return array{flags: array<string, bool|string>, payloads: array<string, mixed>} payloads only where present */
    public function getAllFlagsAndPayloads(
        ?string $distinctId = null,
        array $personProperties = [],
        array $groups = [],
        array $groupProperties = [],
        ?bool $onlyEvaluateLocally = null,
    ): array {
        $all = $this->allResults($distinctId, [$personProperties, $groups, $groupProperties], $onlyEvaluateLocally ?? $this->onlyEvaluateLocally);
        return [
            'flags' => array_map(fn (array $r) => $r['value'], $all),
            'payloads' => array_map(fn (array $r) => $r['payload'], array_filter($all, fn (array $r) => $r['payload'] !== null)),
        ];
    }

    /**
     * Evaluate every flag once; read the snapshot freely and pass it to capture(..., flags: $snapshot).
     */
    public function evaluateFlags(
        ?string $distinctId = null,
        array $personProperties = [],
        array $groups = [],
        array $groupProperties = [],
        ?bool $onlyEvaluateLocally = null,
    ): FlagSnapshot {
        $all = $this->allResults($distinctId, [$personProperties, $groups, $groupProperties], $onlyEvaluateLocally ?? $this->onlyEvaluateLocally);
        return new FlagSnapshot(
            array_map(fn (array $r) => $r['value'], $all),
            array_map(fn (array $r) => $r['payload'], array_filter($all, fn (array $r) => $r['payload'] !== null)),
        );
    }

    /**
     * Payload of a remote-config flag, decrypted when stored encrypted. Needs a secret key (the server only opens
     * encrypted payloads for secret keys). Null when unknown or on failure.
     */
    public function getRemoteConfigPayload(string $key): mixed
    {
        if ($this->secretKey === null) {
            throw new LucidaException('getRemoteConfigPayload requires a secret key');
        }
        if ($this->disabled) {
            return null;
        }
        $url = $this->host . '/v1/flags/' . rawurlencode($key) . '/remote-config';
        [$status, , $out] = $this->request($url, null, ['Authorization: Bearer ' . $this->secretKey]);
        // 404: unknown key or not a remote-config flag
        $j = $status >= 200 && $status < 300 && is_string($out) ? json_decode($out, true) : null;
        return is_array($j) ? ($j['payload'] ?? null) : null;
    }

    /** @param array<string, string>|null $groups */
    private function attachFlags(string $id, array $props, ?array $groups, bool $send, ?FlagSnapshot $snapshot): array
    {
        if ($snapshot !== null) {
            return FlagSnapshot::eventProps($props, $snapshot->values);
        }
        if (!$send) {
            return $props;
        }
        $all = $this->allResults($id, [[], $groups ?? [], []], $this->onlyEvaluateLocally);
        return FlagSnapshot::eventProps($props, array_map(fn (array $r) => $r['value'], $all));
    }

    private function pollIfStale(): void
    {
        if ($this->secretKey !== null && $this->sink === null && $this->flagPollInterval > 0
            && ($this->defs === null || $this->now() - $this->lastPoll >= $this->flagPollInterval)) {
            $this->reloadFeatureFlags();
        }
    }

    /** @return array{0: array<string, array>, 1: bool} locally decided results; true when something needs the server */
    private function localAll(string $id, array $o): array
    {
        $this->pollIfStale();
        if ($this->defs === null) {
            return [[], true];
        }
        $all = Evaluator::evaluateAll($this->defs, [
            'distinct_id' => $id, 'groups' => $o[1], 'person_properties' => $o[0], 'group_properties' => $o[2],
            'app_contexts' => $this->appContexts ?? [],
        ]);
        $all = array_filter($all, fn (array $r) => $r['reason'] !== 'app_context_excluded');
        $out = [];
        foreach ($all as $k => $r) {
            if ($r['reason'] !== Evaluator::REQUIRES_SERVER) {
                $out[$k] = self::result((string) $k, $r['value'], $r['payload'], $r['reason'], true, null);
            }
        }
        return [$out, count($out) !== count($all)];
    }

    private function resolve(string $key, string $id, array $o, bool $onlyLocal): ?array
    {
        $local = $this->localAll($id, $o)[0][$key] ?? null;
        return $local ?? ($onlyLocal ? null : ($this->remote($id, $o, [$key])[$key] ?? null));
    }

    /** @return array<string, array> */
    private function allResults(?string $distinctId, array $o, bool $onlyLocal): array
    {
        $id = $distinctId ?? Context::current()['distinct_id'];
        if ($this->disabled || $id === null) {
            return [];
        }
        [$res, $undecided] = $this->localAll($id, $o);
        return $undecided && !$onlyLocal ? $res + $this->remote($id, $o, null) : $res;
    }

    /** @return array<string, array> */
    private function remote(string $id, array $o, ?array $keys): array
    {
        $body = ['api_key' => $this->apiKey, 'distinct_id' => $id, 'groups' => (object) $o[1], 'person_properties' => (object) $o[0], 'group_properties' => (object) $o[2]];
        if ($keys !== null) {
            $body['flag_keys'] = $keys;
        }
        if ($this->appContexts) {
            $body['app_contexts'] = $this->appContexts;
        }
        // Pre-identify id from Context::with(['anon_distinct_id' => ...]): the gateway links it to $id.
        if (($anon = Context::current()['anon_distinct_id']) !== null) {
            $body['anon_distinct_id'] = $anon;
        }
        [$status, , $out] = $this->postWithRetry('/v1/flags', $body);
        $j = $status >= 200 && $status < 300 && is_string($out) ? json_decode($out, true) : null;
        $res = [];
        foreach ((is_array($j) && is_array($j['flags'] ?? null)) ? $j['flags'] : [] as $k => $f) {
            $v = $f['value'] ?? (($f['variant'] ?? null) ?: (bool) ($f['enabled'] ?? false));
            $res[$k] = self::result((string) $k, $v, $f['payload'] ?? null, $f['reason'] ?? 'remote', false, $j['request_id'] ?? null);
        }
        return $res;
    }

    private static function result(string $key, mixed $value, mixed $payload, string $reason, bool $local, ?string $requestId): array
    {
        return ['key' => $key, 'value' => $value, 'payload' => $payload, 'reason' => $reason, 'locally_evaluated' => $local, 'request_id' => $requestId];
    }

    private function expose(string $distinctId, array $r): void
    {
        $id = "$distinctId\0{$r['key']}\0" . var_export($r['value'], true);
        if (isset($this->seen[$id])) {
            return;
        }
        if (count($this->seen) >= 10000) {
            $this->seen = [];
        }
        $this->seen[$id] = true;
        $props = ['$feature_flag' => $r['key'], '$feature_flag_response' => $r['value'], 'locally_evaluated' => $r['locally_evaluated']];
        if ($r['request_id'] !== null) {
            $props['$feature_flag_request_id'] = $r['request_id'];
        }
        $this->capture($distinctId, '$feature_flag_called', $props);
        $this->capture($distinctId, '$exposure', $props);
    }
}

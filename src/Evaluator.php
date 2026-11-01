<?php
declare(strict_types=1);

namespace Lucida;


/** Local flag evaluator; behaviour pinned by testdata/flags vectors (see testdata/flags/README.md). */
final class Evaluator
{
    public const REQUIRES_SERVER = 'requires_server';
    private const UNIT = 4503599627370496.0; // 16**13

    public static function hash01(string $text): float
    {
        return hexdec(substr(hash('sha256', $text), 0, 13)) / self::UNIT;
    }

    /** @return array{value: mixed, reason: string, condition_index: int, variant: mixed, payload: mixed} */
    private static function res(mixed $value, string $reason, int $idx = -1, mixed $variant = null, mixed $payload = null): array
    {
        return ['value' => $value, 'reason' => $reason, 'condition_index' => $idx, 'variant' => $variant, 'payload' => $payload];
    }

    private static function off(string $reason, int $idx = -1): array
    {
        return self::res(false, $reason, $idx);
    }

    private static function isNum(mixed $v): bool
    {
        return is_int($v) || is_float($v);
    }

    private static function toFloat(mixed $v): ?float
    {
        if (self::isNum($v)) {
            return (float) $v;
        }
        if (is_string($v) && !str_contains($v, '_') && is_numeric(trim($v))) {
            return (float) trim($v);
        }
        return null;
    }

    private static function equal(mixed $a, mixed $b): bool
    {
        if (self::isNum($a) || self::isNum($b)) {
            return self::isNum($a) && self::isNum($b) && (float) $a === (float) $b;
        }
        return $a === $b;
    }

    /** @return int[]|null */
    private static function semver(mixed $v): ?array
    {
        if (!is_string($v)) {
            return null;
        }
        $s = trim($v);
        if (str_starts_with($s, 'v')) {
            $s = substr($s, 1);
        }
        $s = preg_split('/[-+]/', $s, 2)[0];
        $parts = explode('.', $s);
        foreach ($parts as $p) {
            if (!preg_match('/^[0-9]+$/', $p)) {
                return null;
            }
        }
        return array_map('intval', $parts);
    }

    private static function cmpSemver(mixed $a, mixed $b): ?int
    {
        $pa = self::semver($a);
        $pb = self::semver($b);
        if ($pa === null || $pb === null) {
            return null;
        }
        $n = max(count($pa), count($pb));
        $pa = array_pad($pa, $n, 0);
        $pb = array_pad($pb, $n, 0);
        return $pa <=> $pb;
    }

    private static function regex(string $pattern, string $s): ?bool
    {
        $r = @preg_match('~' . str_replace('~', '\~', $pattern) . '~u', $s);
        return $r === false ? null : $r === 1;
    }

    private static function match(string $op, mixed $prop, bool $present, mixed $want, bool $semver, ?float $now = null): bool
    {
        $isSet = $present && $prop !== null;
        if ($op === 'is_set') {
            return $isSet;
        }
        if ($op === 'is_not_set') {
            return !$isSet;
        }
        if (!$isSet) {
            return false;
        }
        switch ($op) {
            case 'eq':
            case 'neq':
                if ($semver) {
                    $c = self::cmpSemver($prop, $want);
                    return $c !== null && (($c === 0) === ($op === 'eq'));
                }
                return self::equal($prop, $want) === ($op === 'eq');
            case 'contains':
            case 'ncontains':
            case 'regex':
            case 'nregex':
                if (!is_string($prop) || !is_string($want)) {
                    return false;
                }
                if ($op === 'contains' || $op === 'ncontains') {
                    return str_contains($prop, $want) === ($op === 'contains');
                }
                $r = self::regex($want, $prop);
                return $r !== null && $r === ($op === 'regex');
            case 'gt':
            case 'gte':
            case 'lt':
            case 'lte':
                if ($semver) {
                    $c = self::cmpSemver($prop, $want);
                } else {
                    $x = self::toFloat($prop);
                    $y = self::toFloat($want);
                    $c = ($x === null || $y === null) ? null : $x <=> $y;
                }
                if ($c === null) {
                    return false;
                }
                return match ($op) {'gt' => $c > 0, 'gte' => $c >= 0, 'lt' => $c < 0, default => $c <= 0};
            case 'in':
            case 'not_in':
                if (!is_array($want)) {
                    return false;
                }
                $any = false;
                foreach ($want as $w) {
                    $any = $any || self::equal($prop, $w);
                }
                return $any === ($op === 'in');
        }
        return OpsV2::match(
            $op, $prop, $want, $now ?? microtime(true),
            static fn ($v) => self::toFloat($v), static fn ($v) => self::semver($v), static fn ($a, $b) => self::cmpSemver($a, $b),
        );
    }

    private static function filterMatches(array $fl, array $c, array $resolved): bool
    {
        $t = $fl['type'] ?? null;
        $want = $fl['value'] ?? null;
        $op = (string) ($fl['op'] ?? '');
        $now = OpsV2::parseNow($c['now'] ?? null);
        if ($t === 'app_version') {
            $v = $c['app_version'] ?? '';
            return self::match($op, $v, $v !== '', $want, true, $now);
        }
        if ($t === 'flag') {
            $r = $resolved[$fl['key'] ?? ''] ?? null;
            return $r !== null && self::match($op, $r['value'], true, $want, false, $now);
        }
        $props = match ($t) {
            'person' => $c['person_properties'] ?? null,
            'group' => ($c['group_properties'] ?? [])[$fl['group_type'] ?? ''] ?? null,
            'device' => $c['device_properties'] ?? null,
            'geo' => $c['geo_properties'] ?? null,
            default => false,
        };
        if ($props === false) {
            return false;
        }
        $props ??= [];
        $key = (string) ($fl['key'] ?? '');
        return self::match($op, $props[$key] ?? null, array_key_exists($key, $props), $want, false, $now);
    }

    private static function valueMatches(mixed $got, mixed $want): bool
    {
        if (is_bool($want)) {
            return is_bool($got) ? $got === $want : $want;
        }
        return $got === $want;
    }

    private static function bucketingKey(array $f, array $c): string
    {
        return self::unitKey($f['aggregation'] ?? [], $c);
    }

    private static function unitKey(array $agg, array $c): string
    {
        return match ($agg['kind'] ?? null) {
            'group' => (string) (($c['groups'] ?? [])[$agg['group_type'] ?? ''] ?? ''),
            'device' => (string) ($c['device_id'] ?? ''),
            default => (string) (($c['bucketing_key'] ?? '') !== '' ? $c['bucketing_key'] : ($c['distinct_id'] ?? '')),
        };
    }

    private static function pickVariant(array $f, array $cond, string $key): array
    {
        $variants = $f['variants'];
        $override = $cond['variant_override'] ?? '';
        if ($override !== '') {
            foreach ($variants as $v) {
                if ($v['key'] === $override) {
                    return $v;
                }
            }
        }
        $h = self::hash01($f['key'] . '.' . ($f['salt'] ?? '') . '.variant.' . $key) * 100;
        $cum = 0.0;
        foreach ($variants as $v) {
            $cum += $v['rollout_pct'] ?? 0;
            if ($h < $cum) {
                return $v;
            }
        }
        return $variants[count($variants) - 1];
    }

    private static function undecidable(array $fl, array $resolved): bool
    {
        if (($fl['type'] ?? null) === 'flag') {
            $r = $resolved[$fl['key'] ?? ''] ?? null;
            return $r !== null && $r['reason'] === self::REQUIRES_SERVER;
        }
        return ($fl['type'] ?? null) === 'cohort';
    }

    /** A stored value of a sticky flag decides once a condition's filters passed (LC-EXT-29). */
    private static function sticky(array $f, array $cond, int $i, array $c): ?array
    {
        $stored = empty($f['sticky']) ? null : ($c['sticky_assignments'][$f['key']] ?? null);
        if ($stored === null) {
            return null;
        }
        if (($f['type'] ?? '') === 'multivariate' && !empty($f['variants'])) {
            $over = $cond['variant_override'] ?? null;
            foreach ($f['variants'] as $v) {
                if ($v['key'] === $over) {
                    return null; // the override wins
                }
            }
            foreach ($f['variants'] as $v) {
                if ($v['key'] === $stored) {
                    return self::res($v['key'], 'sticky_bucket', $i, $v['key'], $v['payload'] ?? null);
                }
            }
            return null;
        }
        if ($stored !== true) {
            return null;
        }
        return self::res(true, 'sticky_bucket', $i, null, ($f['type'] ?? '') === 'json' ? ($f['payload'] ?? null) : null);
    }

    private static function appContextAllowed(array $f, array $c): bool
    {
        $want = $f['app_contexts'] ?? [];
        $have = $c['app_contexts'] ?? [];
        return !$want || !$have || array_intersect($want, $have) !== [];
    }

    public static function evaluate(array $f, array $c, array $resolved): array
    {
        if (!self::appContextAllowed($f, $c)) {
            return self::off('app_context_excluded');
        }
        if (empty($f['active'])) {
            return self::off('inactive');
        }
        if (!empty($f['kill_switch'])) {
            return self::off('kill_switch');
        }
        $server = false;
        foreach ($f['prerequisites'] ?? [] as $p) {
            $r = $resolved[$p['flag']] ?? null;
            if ($r === null) {
                return self::off('prerequisite_missing');
            }
            if ($r['reason'] === self::REQUIRES_SERVER) {
                $server = true;
            } elseif ($r['reason'] === 'prerequisite_cycle' || !self::valueMatches($r['value'], $p['value'] ?? null)) {
                return self::off('prerequisite_failed');
            }
        }
        if ($server) {
            return self::off(self::REQUIRES_SERVER);
        }
        $key = self::bucketingKey($f, $c);
        $conds = array_values($f['conditions'] ?? []);
        $perCondition = false;
        foreach ($conds as $cd) {
            $perCondition = $perCondition || !empty($cd['aggregation']);
        }
        if ($key === '' && (!$perCondition || !empty($f['holdout']) || !empty($f['namespace']))) {
            return self::off('no_bucketing_key');
        }
        $ho = $f['holdout'] ?? null;
        if ($ho && self::hash01('holdout.' . $ho['salt'] . '.' . $key) * 100 < $ho['percentage']) {
            return self::off('holdout');
        }
        $ns = $f['namespace'] ?? null;
        if ($ns && $ns['total'] > 0) {
            $b = (int) floor(self::hash01('namespace.' . $ns['salt'] . '.' . $key) * $ns['total']);
            if ($b < $ns['start'] || $b >= $ns['end']) {
                return self::off('namespace_excluded');
            }
        }
        $skipped = 0;
        foreach ($conds as $i => $cond) {
            $ckey = empty($cond['aggregation']) ? $key : self::unitKey($cond['aggregation'], $c);
            if ($ckey === '') {
                $skipped++;
                continue;
            }
            $filters = $cond['filters'] ?? [];
            foreach ($filters as $fl) {
                if (!self::undecidable($fl, $resolved) && !self::filterMatches($fl, $c, $resolved)) {
                    continue 2;
                }
            }
            foreach ($filters as $fl) {
                if (self::undecidable($fl, $resolved)) {
                    return self::off(self::REQUIRES_SERVER, $i);
                }
            }
            $sticky = empty($cond['aggregation']) ? self::sticky($f, $cond, $i, $c) : null; // the store keys on the flag-level unit
            if ($sticky !== null) {
                return $sticky;
            }
            if (self::hash01($f['key'] . '.' . ($f['salt'] ?? '') . '.' . $ckey) * 100 >= ($cond['rollout_pct'] ?? 0)) {
                if (!empty($f['early_exit'])) {
                    return self::off('out_of_rollout', $i);
                }
                continue;
            }
            if (($f['type'] ?? '') === 'multivariate' && !empty($f['variants'])) {
                $v = self::pickVariant($f, $cond, $ckey);
                return self::res($v['key'], 'condition_match', $i, $v['key'], $v['payload'] ?? null);
            }
            return self::res(true, 'condition_match', $i, null, ($f['type'] ?? '') === 'json' ? ($f['payload'] ?? null) : null);
        }
        return self::off($conds && $skipped === count($conds) ? 'no_bucketing_key' : 'no_condition_match');
    }

    /** @return string[] */
    private static function deps(array $f): array
    {
        $d = array_map(static fn ($p) => $p['flag'], $f['prerequisites'] ?? []);
        foreach ($f['conditions'] ?? [] as $cond) {
            foreach ($cond['filters'] ?? [] as $fl) {
                if (($fl['type'] ?? null) === 'flag') {
                    $d[] = $fl['key'];
                }
            }
        }
        return $d;
    }

    /**
     * Evaluate every flag, resolving prerequisites and `flag` filters depth-first.
     *
     * @param array[] $flags
     * @return array<string, array>
     */
    public static function evaluateAll(array $flags, array $ctx): array
    {
        $byKey = [];
        foreach ($flags as $f) {
            $byKey[$f['key']] = $f;
        }
        $out = [];
        $state = [];
        $visit = function (string $key) use (&$visit, &$out, &$state, $byKey, $ctx): void {
            if (!isset($byKey[$key]) || isset($state[$key])) {
                return;
            }
            $f = $byKey[$key];
            $state[$key] = 1;
            $cyc = false;
            foreach (self::deps($f) as $dep) {
                $visit($dep);
                if (($state[$dep] ?? 0) === 1 || (isset($out[$dep]) && $out[$dep]['reason'] === 'prerequisite_cycle')) {
                    $cyc = true;
                }
            }
            $out[$key] = $cyc ? self::off('prerequisite_cycle') : self::evaluate($f, $ctx, $out);
            $state[$key] = 2;
        };
        foreach ($flags as $f) {
            $visit($f['key']);
        }
        return $out;
    }
}

<?php
declare(strict_types=1);

namespace Lucida;

/** Spec v2 operators (testdata/flags/README.md, "Spec v2"); port of go/internal/flageval/ops_v2.go. */
final class OpsV2
{
    private const UNIT = ['h' => 3600, 'd' => 86400, 'w' => 604800, 'm' => 2592000, 'y' => 31536000];
    private const RFC3339 = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/';
    private const SEMVER_RANGE = ['semver_tilde', 'semver_caret', 'semver_wildcard'];

    private static function isNum(mixed $v): bool
    {
        return is_int($v) || is_float($v);
    }

    public static function relativeSeconds(string $s): ?int
    {
        return preg_match('/^-?(\d{1,6})([hdwmy])$/', trim($s), $m) ? (int) $m[1] * self::UNIT[$m[2]] : null;
    }

    /** Evaluation clock in unix seconds (fractions kept): Context.now when it parses, else the wall clock. */
    public static function parseNow(mixed $s): float
    {
        return is_string($s) ? (self::rfc($s) ?? microtime(true)) : microtime(true);
    }

    private static function rfc(string $s): ?float
    {
        if (!preg_match(self::RFC3339, $s)) {
            return null;
        }
        try {
            $d = new \DateTimeImmutable($s);
        } catch (\Exception) {
            return null;
        }
        return (float) $d->format('U') + (float) ('0.' . ($d->format('u')));
    }

    private static function instant(mixed $v, float $now, bool $rel): ?float
    {
        if (self::isNum($v)) {
            return (float) $v;
        }
        if (!is_string($v)) {
            return null;
        }
        $s = trim($v);
        if ($rel && ($secs = self::relativeSeconds($s)) !== null) {
            return $now - $secs;
        }
        if (($t = self::rfc($s)) !== null) {
            return $t;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return (float) gmmktime(0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1]);
        }
        return null;
    }

    /**
     * @param callable(mixed): ?array $parse
     * @return array{0: int[], 1: int[]}|null
     */
    public static function semverRange(string $op, mixed $want, callable $parse): ?array
    {
        if (!is_string($want)) {
            return null;
        }
        $s = trim($want);
        if (str_starts_with($s, 'v')) {
            $s = substr($s, 1);
        }
        $parts = explode('.', $s);
        if ($op === 'semver_wildcard') {
            if (!in_array(end($parts), ['*', 'x', 'X'], true)) {
                return null;
            }
            if (count($parts) === 1) {
                return [[0], [1 << 30]];
            }
            $lo = $parse(implode('.', array_slice($parts, 0, -1)));
            if ($lo === null) {
                return null;
            }
            $hi = $lo;
            $hi[count($hi) - 1]++;
            return [$lo, $hi];
        }
        $p = $parse($want);
        if ($p === null) {
            return null;
        }
        $p = array_pad($p, 3, 0);
        [$a, $b, $c] = $p;
        if ($op === 'semver_tilde' && count($parts) === 1) {
            $hi = [$a + 1, 0, 0];
        } elseif ($op === 'semver_tilde' || ($a === 0 && $b > 0)) {
            $hi = [$a, $b + 1, 0];
        } elseif ($a > 0) {
            $hi = [$a + 1, 0, 0];
        } else {
            $hi = [0, 0, $c + 1];
        }
        return [$p, $hi];
    }

    private static function cmpParts(array $a, array $b): int
    {
        $n = max(count($a), count($b));
        return array_pad($a, $n, 0) <=> array_pad($b, $n, 0);
    }

    /**
     * @param callable(mixed): ?float $toFloat
     * @param callable(mixed): ?array $parse
     * @param callable(mixed, mixed): ?int $cmpSemver
     */
    public static function match(string $op, mixed $prop, mixed $want, float $now, callable $toFloat, callable $parse, callable $cmpSemver): bool
    {
        switch ($op) {
            case 'icontains':
            case 'not_icontains':
            case 'starts_with':
            case 'not_starts_with':
            case 'ends_with':
            case 'not_ends_with':
                if (!is_string($prop) || !is_string($want)) {
                    return false;
                }
                if ($op === 'icontains' || $op === 'not_icontains') {
                    return str_contains(mb_strtolower($prop), mb_strtolower($want)) === ($op === 'icontains');
                }
                if ($op === 'starts_with' || $op === 'not_starts_with') {
                    return str_starts_with($prop, $want) === ($op === 'starts_with');
                }
                return str_ends_with($prop, $want) === ($op === 'ends_with');
            case 'between':
            case 'not_between':
                $x = $toFloat($prop);
                if (!is_array($want) || count($want) !== 2 || !array_is_list($want) || $x === null || !self::isNum($want[0]) || !self::isNum($want[1])) {
                    return false;
                }
                return ($x >= $want[0] && $x <= $want[1]) === ($op === 'between');
            case 'is_date_before':
            case 'is_date_after':
                $p = self::instant($prop, $now, false);
                $w = self::instant($want, $now, true);
                if ($p === null || $w === null) {
                    return false;
                }
                return $op === 'is_date_before' ? $p < $w : $p > $w;
            case 'semver_eq':
            case 'semver_neq':
            case 'semver_gt':
            case 'semver_gte':
            case 'semver_lt':
            case 'semver_lte':
                $c = $cmpSemver($prop, $want);
                if ($c === null) {
                    return false;
                }
                return match ($op) {
                    'semver_eq' => $c === 0, 'semver_neq' => $c !== 0, 'semver_gt' => $c > 0,
                    'semver_gte' => $c >= 0, 'semver_lt' => $c < 0, default => $c <= 0,
                };
        }
        if (in_array($op, self::SEMVER_RANGE, true)) {
            $v = $parse($prop);
            $r = self::semverRange($op, $want, $parse);
            return $v !== null && $r !== null && self::cmpParts($v, $r[0]) >= 0 && self::cmpParts($v, $r[1]) < 0;
        }
        return false;
    }
}

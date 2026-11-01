<?php
declare(strict_types=1);

namespace Lucida;

/**
 * Request-scoped ambient identity. PHP-FPM serves one request per process, so a static stack is safe there; under
 * Octane/Swoole/RoadRunner always go through with() (it pops in a finally) and pass 'fresh' => true per request.
 * Explicit capture arguments always win over the context.
 */
final class Context
{
    public const HEADER_DISTINCT = 'x-lucida-distinct-id';
    public const HEADER_SESSION = 'x-lucida-session-id';

    /** @var array<int, array{distinct_id: ?string, session_id: ?string, properties: array, anon_distinct_id: ?string}> */
    private static array $stack = [];

    /**
     * @param array{distinct_id?: ?string, session_id?: ?string, properties?: array, anon_distinct_id?: ?string, fresh?: bool} $opts
     */
    public static function with(array $opts, callable $fn): mixed
    {
        $base = !empty($opts['fresh']) ? self::empty() : self::current();
        self::$stack[] = [
            'distinct_id' => $opts['distinct_id'] ?? $base['distinct_id'],
            'session_id' => $opts['session_id'] ?? $base['session_id'],
            'properties' => array_merge($base['properties'], $opts['properties'] ?? []),
            'anon_distinct_id' => $opts['anon_distinct_id'] ?? $base['anon_distinct_id'],
        ];
        try {
            return $fn();
        } finally {
            array_pop(self::$stack);
        }
    }

    /** @return array{distinct_id: ?string, session_id: ?string, properties: array, anon_distinct_id: ?string} */
    public static function current(): array
    {
        return self::$stack ? self::$stack[array_key_last(self::$stack)] : self::empty();
    }

    /**
     * Reads the browser tracing headers from a header map (any case, or $_SERVER style HTTP_X_LUCIDA_*).
     * NOTE: these headers are client-controlled; never use them for authentication or authorization.
     *
     * @return array{distinct_id: ?string, session_id: ?string}
     */
    public static function fromHeaders(array $headers): array
    {
        $h = [];
        foreach ($headers as $k => $v) {
            $h[preg_replace('/^http-/', '', str_replace('_', '-', strtolower((string) $k)))] = $v;
        }
        return ['distinct_id' => self::clean($h[self::HEADER_DISTINCT] ?? null), 'session_id' => self::clean($h[self::HEADER_SESSION] ?? null)];
    }

    private static function clean(mixed $v): ?string
    {
        if (is_array($v)) {
            $v = $v[0] ?? null;
        }
        if (!is_string($v)) {
            return null;
        }
        $s = substr(trim((string) preg_replace('/[\x00-\x1f\x7f]/', '', $v)), 0, 1000);
        return $s === '' ? null : $s;
    }

    private static function empty(): array
    {
        return ['distinct_id' => null, 'session_id' => null, 'properties' => [], 'anon_distinct_id' => null];
    }
}

<?php
declare(strict_types=1);

namespace Lucida;

/** Flags evaluated once for one distinct id. Reads never call the network or emit exposure events. */
final class FlagSnapshot
{
    /** @var array<string, true> */
    private array $accessed = [];

    /**
     * @param array<string, bool|string> $values
     * @param array<string, mixed> $payloads
     */
    public function __construct(public readonly array $values = [], public readonly array $payloads = [])
    {
    }

    /** `$feature/<key>` per flag plus sorted `$active_feature_flags`; caller properties win. */
    public static function eventProps(array $props, array $values): array
    {
        if (!$values) {
            return $props;
        }
        $out = [];
        $active = [];
        foreach ($values as $k => $v) {
            $out['$feature/' . $k] = $v;
            if ($v !== false) {
                $active[] = (string) $k;
            }
        }
        sort($active);
        $out['$active_feature_flags'] = $active;
        return $props + $out;
    }

    public function getFlag(string $key): bool|string|null
    {
        $this->accessed[$key] = true;
        return $this->values[$key] ?? null;
    }

    public function isEnabled(string $key): bool
    {
        $v = $this->getFlag($key);
        return $v !== null && $v !== false;
    }

    public function getPayload(string $key): mixed
    {
        return $this->payloads[$key] ?? null;
    }

    /** @return string[] */
    public function keys(): array
    {
        $k = array_map('strval', array_keys($this->values));
        sort($k);
        return $k;
    }

    /** @param string[] $keys */
    public function only(array $keys): self
    {
        $want = array_flip($keys);
        return new self(array_intersect_key($this->values, $want), array_intersect_key($this->payloads, $want));
    }

    public function onlyAccessed(): self
    {
        return $this->only(array_map('strval', array_keys($this->accessed)));
    }

    public function eventProperties(array $props = []): array
    {
        return self::eventProps($props, $this->values);
    }
}

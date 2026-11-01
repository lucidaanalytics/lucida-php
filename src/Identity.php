<?php
declare(strict_types=1);

namespace Lucida;

/** Super properties, person-property setters and identify/group dedupe for Client. Expects Client::capture(). */
trait Identity
{
    private const IDENTITY_DEDUPE_SIZE = 1000;

    /** @var array<string, mixed> */
    private array $superProps = [];
    /** @var array<string, true> insertion-ordered LRU of sent identify/group calls */
    private array $identities = [];

    /** Adds properties to every later event; context and per-call properties win. */
    public function register(array $properties): void
    {
        $this->superProps = array_merge($this->superProps, $properties);
    }

    public function unregister(string $key): void
    {
        unset($this->superProps[$key]);
    }

    /** `$set` event updating person properties. */
    public function setPersonProperties(?string $distinctId, array $properties): void
    {
        $this->capture($distinctId, '$set', set: $properties);
    }

    /** `$set` event with `$set_once`: only fills properties that are not set yet. */
    public function setPersonPropertiesOnce(?string $distinctId, array $properties): void
    {
        $this->capture($distinctId, '$set', setOnce: $properties);
    }

    /** True the first time this exact call (kind, id, properties) is seen by the client; bounded LRU. */
    private function firstIdentity(mixed ...$parts): bool
    {
        $key = hash('sha256', json_encode($parts, JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '');
        $fresh = !isset($this->identities[$key]);
        unset($this->identities[$key]);
        $this->identities[$key] = true;
        if (count($this->identities) > self::IDENTITY_DEDUPE_SIZE) {
            unset($this->identities[array_key_first($this->identities)]);
        }
        return $fresh;
    }
}

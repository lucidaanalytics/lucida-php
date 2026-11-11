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
    /** @var array<string, string> event uuid => dedupe key, until the event is dropped */
    private array $identityKeys = [];

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

    /**
     * The uuid to send an identify/group event under the first time this exact call (kind, id, properties) is seen
     * by the client (bounded LRU), else null. releaseIdentities() frees it again when the event is dropped.
     */
    private function identityUuid(mixed ...$parts): ?string
    {
        $key = hash('sha256', json_encode($parts, JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '');
        $fresh = !isset($this->identities[$key]);
        unset($this->identities[$key]);
        $this->identities[$key] = true;
        if (count($this->identities) > self::IDENTITY_DEDUPE_SIZE) {
            unset($this->identities[array_key_first($this->identities)]);
        }
        if (!$fresh) {
            return null;
        }
        $uuid = self::uuid7();
        $this->identityKeys[$uuid] = $key;
        if (count($this->identityKeys) > self::IDENTITY_DEDUPE_SIZE) {
            unset($this->identityKeys[array_key_first($this->identityKeys)]);
        }
        return $uuid;
    }

    /** Forgets the calls whose events were dropped (rejected batch, full queue) so repeating them is sent again. */
    private function releaseIdentities(array $events): void
    {
        foreach ($events as $e) {
            $uuid = $e['uuid'] ?? '';
            if (isset($this->identityKeys[$uuid])) {
                unset($this->identities[$this->identityKeys[$uuid]], $this->identityKeys[$uuid]);
            }
        }
    }
}

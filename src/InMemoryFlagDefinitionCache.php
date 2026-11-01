<?php
declare(strict_types=1);

namespace Lucida;

/** Reference implementation: process-local, fetches again once $ttl seconds have passed. */
final class InMemoryFlagDefinitionCache implements FlagDefinitionCacheProvider
{
    private ?array $defs = null;
    private float $at = 0.0;

    public function __construct(private readonly float $ttl = 30.0)
    {
    }

    public function flagDefinitions(): ?array
    {
        return $this->defs;
    }

    public function shouldFetchFlagDefinitions(): bool
    {
        return $this->defs === null || microtime(true) - $this->at >= $this->ttl;
    }

    public function onFlagDefinitionsReceived(array $definitions): void
    {
        $this->defs = $definitions;
        $this->at = microtime(true);
    }

    public function shutdown(): void
    {
    }
}

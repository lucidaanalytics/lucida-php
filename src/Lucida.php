<?php
declare(strict_types=1);

namespace Lucida;

/**
 * Process-wide facade: `Lucida::init('lc_pk_...', ['secretKey' => ...])` once at boot, then `Lucida::capture(...)`
 * anywhere. Options are the Client constructor's named arguments. Calls before init() are ignored (null/false).
 */
final class Lucida
{
    private static ?Client $client = null;

    /** Creates the shared client (flushing a previous one). */
    public static function init(string $apiKey, array $options = []): Client
    {
        self::$client?->shutdown();
        return self::$client = new Client($apiKey, ...$options);
    }

    public static function client(): ?Client
    {
        return self::$client;
    }

    public static function capture(?string $distinctId, string $event, array $properties = [], mixed ...$rest): void
    {
        self::$client?->capture($distinctId, $event, $properties, ...$rest);
    }

    public static function identify(?string $distinctId, ?array $properties = null, ?array $propertiesSetOnce = null): void
    {
        self::$client?->identify($distinctId, $properties, $propertiesSetOnce);
    }

    public static function alias(string $distinctId, string $alias): void
    {
        self::$client?->alias($distinctId, $alias);
    }

    public static function groupIdentify(string $groupType, string $groupKey, array $properties = [], ?string $distinctId = null): void
    {
        self::$client?->groupIdentify($groupType, $groupKey, $properties, $distinctId);
    }

    public static function setPersonProperties(?string $distinctId, array $properties): void
    {
        self::$client?->setPersonProperties($distinctId, $properties);
    }

    public static function setPersonPropertiesOnce(?string $distinctId, array $properties): void
    {
        self::$client?->setPersonPropertiesOnce($distinctId, $properties);
    }

    public static function register(array $properties): void
    {
        self::$client?->register($properties);
    }

    public static function unregister(string $key): void
    {
        self::$client?->unregister($key);
    }

    public static function getFeatureFlag(string $key, ?string $distinctId = null, mixed ...$rest): mixed
    {
        return self::$client?->getFeatureFlag($key, $distinctId, ...$rest);
    }

    public static function isFeatureEnabled(string $key, ?string $distinctId = null, array $personProperties = [], array $groups = []): ?bool
    {
        return self::$client?->isFeatureEnabled($key, $distinctId, $personProperties, $groups);
    }

    public static function getAllFlags(mixed ...$args): array
    {
        return self::$client?->getAllFlags(...$args) ?? [];
    }

    public static function flush(): bool
    {
        return self::$client?->flush() ?? false;
    }

    public static function shutdown(): void
    {
        self::$client?->shutdown();
        self::$client = null;
    }
}

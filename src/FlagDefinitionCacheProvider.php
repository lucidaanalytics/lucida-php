<?php
declare(strict_types=1);

namespace Lucida;

/**
 * Flag definition cache provider: lets many workers (php-fpm children) share one definitions fetch (Redis, APCu,
 * files...). Before each poll the client asks shouldFetchFlagDefinitions(): when false it adopts flagDefinitions()
 * instead of calling the server. A provider that throws means "fetch yourself".
 */
interface FlagDefinitionCacheProvider
{
    /** @return array[]|null cached definitions, or null when nothing is cached */
    public function flagDefinitions(): ?array;

    /** True when this process should call the server (e.g. it won the fetch lock). */
    public function shouldFetchFlagDefinitions(): bool;

    /** @param array[] $definitions store freshly fetched definitions for the other workers */
    public function onFlagDefinitionsReceived(array $definitions): void;

    public function shutdown(): void;
}

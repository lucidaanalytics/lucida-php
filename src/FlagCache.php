<?php
declare(strict_types=1);

namespace Lucida;

final class FlagCache
{
    /** @return array[]|null definitions to use instead of fetching, or null when this process must fetch */
    public static function adopt(?FlagDefinitionCacheProvider $p): ?array
    {
        try {
            return $p === null || $p->shouldFetchFlagDefinitions() ? null : $p->flagDefinitions();
        } catch (\Throwable) {
            return null;
        }
    }

    public static function publish(?FlagDefinitionCacheProvider $p, array $defs): void
    {
        try {
            $p?->onFlagDefinitionsReceived($defs);
        } catch (\Throwable) {
            // a failing cache must not break flag evaluation
        }
    }
}

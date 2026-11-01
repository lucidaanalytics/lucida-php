<?php
declare(strict_types=1);

namespace Lucida;

/** Test-mode capture sink: every event (including flag exposures) lands here and nothing is sent. */
final class Sink
{
    /** @var array[] */
    public array $events = [];

    public function add(array $event): void
    {
        $this->events[] = $event;
    }

    public function clear(): void
    {
        $this->events = [];
    }

    /**
     * @param array|string $src path to a `lucida flags fixture` file, a ['flags' => [...]] body, or a list of definitions
     * @return array[]
     */
    public static function loadFixture(array|string $src): array
    {
        $body = is_string($src) ? json_decode((string) file_get_contents($src), true) : $src;
        $flags = is_array($body) && array_key_exists('flags', $body) ? $body['flags'] : $body;
        if (!is_array($flags) || ($flags && !array_is_list($flags))) {
            throw new \InvalidArgumentException('testMode fixture must be {"flags": [...]} or a list of flag definitions');
        }
        return $flags;
    }
}

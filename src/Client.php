<?php
declare(strict_types=1);

namespace Lucida;

/**
 * Server SDK for Lucida (ext-curl, ext-json). Pass a distinct id on every call, or set one for the request with
 * Client::withContext(). Events queue in memory and are sent in batches by flush(); the destructor flushes too.
 */
final class Client
{
    use Delivery;
    use FlagApi;
    use Identity;

    public const VERSION = '0.1.0';
    public const LIB = 'lucida-php';

    public int $droppedEvents = 0;
    /** testMode only: every captured event (including exposures); nothing is sent. */
    public ?Sink $sink = null;
    /** @var array[] */
    private array $queue = [];
    /** @var array[]|null */
    private ?array $defs = null;
    private ?string $etag = null;
    /** @var array<string, true> */
    private array $seen = [];
    /** @var callable */
    private $transport;
    /** @var callable */
    private $sleeper;
    /** @var (callable(): float)|null */
    private $clock;
    /** @var (callable(LucidaException): void)|null */
    private $onError;
    /** @var (callable(array): ?array)|null */
    private $beforeSend;
    private float $lastFlush;
    private float $lastPoll = 0.0;

    /**
     * @param (callable(string, ?string, array): (int|array))|null $transport fn(url, body|null for GET, headers): status or
     *        [status, retryAfter, body, etag] (tests)
     * @param (callable(LucidaException): void)|null $onError for batches that were dropped or could not be delivered
     * @param (callable(array): ?array)|null $beforeSend returns the event to send, null to drop it; a throw keeps the original
     * @param array|string|null $testMode fixture (path, ['flags' => [...]] or a list of definitions): offline, events go to $sink
     * @param (callable(float, ?float): void)|null $sleeper fn(backoffSeconds, retryAfter) overrides the retry sleep (tests)
     * @param (callable(): float)|null $clock overrides microtime(true) (tests)
     * @param array<string, bool|string> $fallbacks flag key => value served (reason "fallback", no exposure) when evaluation fails
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $host = 'https://capture.lucidace.com',
        private readonly ?string $secretKey = null,
        private readonly int $flushAt = 20,
        private readonly int $maxQueueSize = 1000,
        private bool $disabled = false,
        ?callable $transport = null,
        private readonly int $maxBatchSize = 100,
        private readonly float $flushInterval = 5.0,
        private readonly float $flagPollInterval = 30.0,
        private readonly int $maxRetries = 3,
        private readonly float $retryDelay = 0.5,
        private readonly float $timeout = 10.0,
        private readonly bool $debug = false,
        ?callable $onError = null,
        ?callable $beforeSend = null,
        private bool $onlyEvaluateLocally = false,
        array|string|null $testMode = null,
        ?callable $sleeper = null,
        ?callable $clock = null,
        private readonly ?array $appContexts = null,
        private readonly ?FlagDefinitionCacheProvider $flagDefinitionCache = null,
        private readonly array $fallbacks = [],
    ) {
        $this->transport = $transport ?? [$this, 'curlRequest'];
        $this->sleeper = $sleeper ?? self::sleep(...);
        $this->clock = $clock;
        $this->onError = $onError;
        $this->beforeSend = $beforeSend;
        $this->lastFlush = $this->now();
        if ($testMode !== null) {
            $this->defs = Sink::loadFixture($testMode);
            $this->sink = new Sink();
            $this->onlyEvaluateLocally = true;
            $this->transport = static function (): never {
                throw new \RuntimeException('network disabled in testMode');
            };
        }
    }

    public function __destruct()
    {
        $this->flush();
        try {
            $this->flagDefinitionCache?->shutdown();
        } catch (\Throwable) {
        }
    }

    /**
     * $distinctId may be null to use the Client::withContext() one. $processPersonProfile = false sends a personless
     * event ($process_person_profile: false); a random UUID distinct id is generated when none is available.
     */
    public function capture(
        ?string $distinctId,
        string $event,
        array $properties = [],
        ?array $groups = null,
        ?string $timestamp = null,
        ?string $uuid = null,
        ?array $set = null,
        ?array $setOnce = null,
        bool $processPersonProfile = true,
        bool $sendFeatureFlags = false,
        ?FlagSnapshot $flags = null,
    ): void {
        if ($this->disabled) {
            return;
        }
        $ctx = Context::current();
        $props = array_merge($this->superProps, $ctx['properties'], $properties);
        if ($ctx['session_id'] !== null && !array_key_exists('$session_id', $props)) {
            $props['$session_id'] = $ctx['session_id'];
        }
        if (!$processPersonProfile) {
            $props['$process_person_profile'] = false;
        }
        $id = $distinctId ?? $ctx['distinct_id'];
        if (($id === null || $id === '') && !$processPersonProfile) {
            $id = self::uuid4();
        }
        if ($id === null || $id === '') {
            $this->log("capture($event) dropped: no distinct id");
            return;
        }
        $props = $this->attachFlags($id, $props, $groups, $sendFeatureFlags, $flags);
        $this->enqueue($this->buildEvent($id, $event, $props, $groups, $timestamp, $uuid, $set, $setOnce));
    }

    public function identify(?string $distinctId, ?array $properties = null, ?array $propertiesSetOnce = null): void
    {
        if (!$this->firstIdentity('identify', $distinctId, $properties, $propertiesSetOnce)) {
            return;
        }
        $this->capture($distinctId, '$identify', set: $properties, setOnce: $propertiesSetOnce);
    }

    public function alias(string $distinctId, string $alias): void
    {
        $this->capture($distinctId, '$create_alias', ['alias' => $alias, 'distinct_id' => $distinctId]);
    }

    public function groupIdentify(string $groupType, string $groupKey, array $properties = [], ?string $distinctId = null): void
    {
        if (!$this->firstIdentity('group', $groupType, $groupKey, $distinctId, $properties)) {
            return;
        }
        $this->capture($distinctId ?? "\${$groupType}_{$groupKey}", '$groupidentify', [
            '$group_type' => $groupType, '$group_key' => $groupKey, '$group_set' => (object) $properties,
        ]);
    }

    // Request context -----------------------------------------------------------------------

    /**
     * Runs $fn with an ambient distinct id / session id / properties.
     *
     * @param array{distinct_id?: ?string, session_id?: ?string, properties?: array, fresh?: bool} $opts
     */
    public static function withContext(array $opts, callable $fn): mixed
    {
        return Context::with($opts, $fn);
    }

    /** @return array{distinct_id: ?string, session_id: ?string, properties: array} */
    public static function getContext(): array
    {
        return Context::current();
    }

    /**
     * Tracing headers (X-Lucida-Distinct-Id / X-Lucida-Session-Id) from a header map. Client-controlled: never use for auth.
     *
     * @return array{distinct_id: ?string, session_id: ?string}
     */
    public static function contextFromHeaders(array $headers): array
    {
        return Context::fromHeaders($headers);
    }

    private function buildEvent(
        string $distinctId,
        string $event,
        array $properties,
        ?array $groups,
        ?string $timestamp,
        ?string $uuid,
        ?array $set,
        ?array $setOnce,
    ): array {
        $e = [
            'uuid' => $uuid ?? self::uuid7(),
            'event' => $event,
            'distinct_id' => $distinctId,
            'timestamp' => $timestamp ?? gmdate('Y-m-d\TH:i:s') . sprintf('.%03dZ', (int) (fmod(microtime(true), 1) * 1000)),
            'properties' => $properties + ['$lib' => self::LIB, '$lib_version' => self::VERSION], // never empty, so always a JSON object
        ];
        if ($set) {
            $e['$set'] = $set;
        }
        if ($setOnce) {
            $e['$set_once'] = $setOnce;
        }
        if ($groups) {
            $e['$groups'] = $groups;
        }
        return $e;
    }

    private static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr(0x40 | (ord($b[6]) & 0x0F));
        $b[8] = chr(0x80 | (ord($b[8]) & 0x3F));
        $h = bin2hex($b);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
    }

    private static function uuid7(): string
    {
        $ms = (int) (microtime(true) * 1000);
        $b = substr(pack('J', $ms), 2, 6) . random_bytes(10);
        $b[6] = chr(0x70 | (ord($b[6]) & 0x0F));
        $b[8] = chr(0x80 | (ord($b[8]) & 0x3F));
        $h = bin2hex($b);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
    }
}

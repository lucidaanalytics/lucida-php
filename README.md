# lucida (PHP)

Server SDK for Lucida (PHP >= 8.1, ext-curl, ext-json). Pass a distinct id on every call, or set one for the request
with `Client::withContext()`.

## Install

From GitHub, [lucidaanalytics/lucida-php](https://github.com/lucidaanalytics/lucida-php), release `v0.1.0`:

```sh
composer config repositories.lucida vcs https://github.com/lucidaanalytics/lucida-php
composer require "lucida/lucida:^0.1"
```

That is the same as `"repositories": [{"type": "vcs", "url": "https://github.com/lucidaanalytics/lucida-php"}]` in `composer.json`. The Packagist install (`composer require lucida/lucida` with no repository entry) is coming.


```php
$lc = new Lucida\Client('lc_pk_...', host: 'https://capture.lucidace.com', secretKey: 'lc_sk_...');
$lc->capture('u1', 'purchase', ['total' => 12], groups: ['company' => 'acme']);
$lc->identify('u1', ['plan' => 'pro']);
$lc->alias('u1', 'anon-9');
$lc->groupIdentify('company', 'acme', ['seats' => 5]);
$lc->isFeatureEnabled('new-checkout', 'u1', ['plan' => 'pro']);
$lc->shutdown();                        // flushes (the destructor flushes too)
```

## Delivery

PHP has no threads, so there is no background timer: events flush when `flushAt` (20) is reached, when a `capture`
finds `flushInterval` seconds (default 5, `0` disables) elapsed since the last flush, and on `shutdown()` / destructor.

- Events queue in memory (bounded by `maxQueueSize`, oldest dropped, counted in `droppedEvents`); one request carries at most
  `maxBatchSize` (100) events. Bodies over 1 KB are gzipped (`?compression=gzip`).
- Failures retry with exponential backoff and jitter (`maxRetries: 3`, `retryDelay: 0.5`), honoring `Retry-After`
  (capped at 60 s). Retryable: network errors, 408, 429, 5xx. After the retries a batch stays queued for the next `flush()`;
  other 4xx batches are dropped. Both go to `onError: fn (Lucida\LucidaException $e)` (`$e->status`).
- `beforeSend: fn (array $event): ?array` returns the event to send, `null` to drop it; if it throws, the original is sent.
- `timeout` (seconds, default 10), `debug: true` logs through `error_log`, `disabled: true` sends nothing.
- Personless events: `$lc->capture(null, 'pageview', processPersonProfile: false)` sends `$process_person_profile: false`
  and generates a random UUID distinct id when none is available.
- Historical import with the secret key: `$lc->importEvents([['distinct_id' => 'u', 'event' => 'old', 'timestamp' => '2020-01-01T00:00:00Z']])`
  posts to `/v1/s2s/batch` in chunks of 1000 and throws `LucidaException` on a rejected chunk.

## Request context

```php
Lucida\Client::withContext(['distinct_id' => 'u1', 'session_id' => 's1', 'properties' => ['tenant' => 'acme']], function () use ($lc) {
    $lc->capture(null, 'clicked');        // distinct id, $session_id and tenant come from the context
    $lc->capture('other', 'clicked');     // explicit arguments win
});
Lucida\Client::getContext();
Lucida\Client::contextFromHeaders(getallheaders()); // ['distinct_id' =>, 'session_id' =>] from X-Lucida-Distinct-Id / X-Lucida-Session-Id
```

`['fresh' => true]` ignores the enclosing context (use it per request under Octane/Swoole; the stack is process-wide
static, which is safe under PHP-FPM). The tracing headers are client-controlled (control characters stripped, capped at
1000 chars): never use them for authentication or authorization. A `$_SERVER` array works too (`HTTP_X_LUCIDA_*`).

## Flags

- Definitions load from `/v1/flags/definitions` (needs `secretKey`): call `reloadFeatureFlags()` at boot, and flag reads
  re-poll when the definitions are older than `flagPollInterval` seconds (default 30, `0` disables; ETag reused).
- Flags the definitions cannot decide (cohorts, no definitions yet) fall back to `POST /v1/flags`, unless
  `onlyEvaluateLocally: true` (client option or per call).
- `getFeatureFlag`, `isFeatureEnabled`, `getFeatureFlagPayload`, `getAllFlags`, `getAllFlagsAndPayloads`
  (`['flags' =>, 'payloads' =>]`) and `getFeatureFlagResult` (`value`, `payload`, `reason`, `locally_evaluated`,
  `request_id`). Single-flag calls record `$feature_flag_called` and `$exposure` once per user, flag and value (per process).

### Fallbacks and details

`new Client('k', fallbacks: ['new-nav' => false, 'plan' => 'free'])`: when a read fails (network error, no definitions,
quota limit, unknown flag) and the key is listed, the flag returns that value with reason `fallback` and records no
exposure. `getFeatureFlagDetails($key, $id)` (same as `getFeatureFlagResult`) returns `value`, `payload`, `variant`,
`reason`, `condition_index`, `request_id` and `locally_evaluated`.

## Test mode

```php
$lc = new Lucida\Client('k', testMode: 'flags-fixture.json'); // path, ['flags' => [...]] or a list of definitions
$lc->capture('u', 'e');
$lc->sink->events;  // every captured event (including exposures); nothing is sent, the network is refused
$lc->sink->clear();
```

`php tests/run.php` (or `make sdk-php`) runs the tests, including the shared `testdata/flags` conformance vectors
(10,000+) and the `testdata/events/server_sdk.json` wire fixtures.

---

This repository is a read-only mirror of the Lucida monorepo (synced by a script, never edited by hand); releases and provenance attestations are built here by GitHub Actions. Issues are welcome, changes land upstream.

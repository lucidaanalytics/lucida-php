<?php
declare(strict_types=1);

namespace Lucida;

/**
 * Queue, interval flush, retry/backoff and HTTP for Client. PHP has no threads: the flush interval is checked
 * on each capture, and the destructor / shutdown() flush the rest. Uses Client's private state.
 */
trait Delivery
{
    /** Sends everything queued in batches of maxBatchSize. False when a batch could not be delivered (kept for next flush). */
    public function flush(): bool
    {
        $this->lastFlush = $this->now();
        while ($this->queue) {
            $batch = array_splice($this->queue, 0, $this->maxBatchSize);
            if (!$this->sendBatch($batch)) {
                $this->queue = array_merge($batch, $this->queue);
                return false;
            }
        }
        return true;
    }

    public function shutdown(): void
    {
        $this->flush();
        $this->disabled = true;
    }

    /**
     * Historical import via POST /v1/s2s/batch (secret key, caller timestamps kept), chunks of 1000.
     *
     * @param array[] $events each with distinct_id, event and optional properties/timestamp/uuid/set/set_once
     * @throws LucidaException on a rejected chunk or a missing secret key
     */
    public function importEvents(array $events): int
    {
        if ($this->secretKey === null) {
            throw new LucidaException('importEvents requires a secret key');
        }
        if ($this->disabled) {
            return 0;
        }
        $wire = array_map(fn (array $e): array => $this->buildEvent(
            $e['distinct_id'], $e['event'], $e['properties'] ?? [], null, $e['timestamp'] ?? null, $e['uuid'] ?? null,
            $e['set'] ?? null, $e['set_once'] ?? null,
        ), $events);
        if ($this->sink !== null) {
            array_map([$this->sink, 'add'], $wire);
            return count($wire);
        }
        $sent = 0;
        foreach (array_chunk($wire, 1000) as $chunk) {
            [$status] = $this->postWithRetry('/v1/s2s/batch', ['batch' => $chunk, 'sent_at' => $this->sentAt()], ['Authorization: Bearer ' . $this->secretKey]);
            if ($status < 200 || $status >= 300) {
                throw new LucidaException("import failed: status $status after $sent events", $status);
            }
            $sent += count($chunk);
        }
        return $sent;
    }

    private function enqueue(array $e): void
    {
        if ($this->beforeSend !== null) {
            try {
                $e = ($this->beforeSend)($e);
            } catch (\Throwable $err) {
                $this->log('beforeSend threw: ' . $err->getMessage()); // keep the original event
            }
            if ($e === null) {
                return;
            }
        }
        if ($this->sink !== null) {
            $this->sink->add($e);
            return;
        }
        if (count($this->queue) >= $this->maxQueueSize) {
            array_shift($this->queue);
            $this->droppedEvents++;
        }
        $this->queue[] = $e;
        if (count($this->queue) >= $this->flushAt || ($this->flushInterval > 0 && $this->now() - $this->lastFlush >= $this->flushInterval)) {
            $this->flush();
        }
    }

    /** True when delivered or permanently rejected (dropped); false when retries ran out on a retryable failure. */
    private function sendBatch(array $batch): bool
    {
        [$status] = $this->postWithRetry('/v1/batch', ['api_key' => $this->apiKey, 'batch' => $batch, 'sent_at' => $this->sentAt()]);
        if ($status >= 200 && $status < 300) {
            return true;
        }
        $later = self::retryable($status);
        $this->report(new LucidaException(sprintf('batch of %d events %s: status %d', count($batch), $later ? 'kept for next flush' : 'dropped', $status), $status));
        return !$later;
    }

    /** @return array{0: int, 1: ?float, 2: ?string, 3: ?string} status, retry-after, body, etag */
    private function postWithRetry(string $path, array $payload, array $extraHeaders = []): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($body === false) {
            return [200, null, null, null]; // unencodable: retrying cannot help, drop it
        }
        $url = $this->host . $path;
        if (strlen($body) >= 1024) {
            $body = gzencode($body);
            $url .= '?compression=gzip';
        }
        $headers = array_merge(['Content-Type: application/json'], $extraHeaders);
        for ($attempt = 0;; $attempt++) {
            $res = $this->request($url, $body, $headers);
            if (($res[0] >= 200 && $res[0] < 300) || !self::retryable($res[0]) || $attempt >= $this->maxRetries) {
                return $res;
            }
            ($this->sleeper)(min(30.0, $this->retryDelay * (2 ** $attempt)) * (0.5 + 0.5 * mt_rand() / mt_getrandmax()), $res[1]);
        }
    }

    /** $body null means GET. Never throws: transport errors become status 0. @return array{0: int, 1: ?float, 2: ?string, 3: ?string} */
    private function request(string $url, ?string $body, array $headers): array
    {
        try {
            $r = ($this->transport)($url, $body, $headers);
        } catch (\Throwable $e) {
            $this->log('request failed: ' . $e->getMessage());
            return [0, null, null, null];
        }
        $r = is_array($r) ? $r : [$r];
        return [(int) $r[0], isset($r[1]) ? (float) $r[1] : null, $r[2] ?? null, $r[3] ?? null];
    }

    private static function retryable(int $status): bool
    {
        return in_array($status, [0, 408, 429], true) || $status >= 500;
    }

    /** Sleeps the backoff step, or Retry-After (capped at 60 s) when longer. */
    private static function sleep(float $backoff, ?float $retryAfter): void
    {
        usleep((int) (max($backoff, min(60.0, max(0.0, $retryAfter ?? 0.0))) * 1e6));
    }

    private function report(LucidaException $e): void
    {
        $this->log($e->getMessage());
        if ($this->onError !== null) {
            try {
                ($this->onError)($e);
            } catch (\Throwable) {
                // an error callback must never break the host app
            }
        }
    }

    private function log(string $msg): void
    {
        if ($this->debug) {
            error_log('[lucida] ' . $msg);
        }
    }

    private function now(): float
    {
        return $this->clock !== null ? ($this->clock)() : microtime(true);
    }

    private function sentAt(): string
    {
        return gmdate('Y-m-d\TH:i:s.000\Z');
    }

    /** @internal default transport: Retry-After/ETag aware, GET when body is null. */
    public function curlRequest(string $url, ?string $body, array $headers): array
    {
        $retryAfter = $etag = null;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => (int) ceil($this->timeout),
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$retryAfter, &$etag): int {
                if (stripos($line, 'retry-after:') === 0) {
                    $retryAfter = trim(substr($line, 12));
                } elseif (stripos($line, 'etag:') === 0) {
                    $etag = trim(substr($line, 5));
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body]);
        }
        $out = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$status, is_numeric($retryAfter) ? (float) $retryAfter : null, is_string($out) ? $out : null, $etag];
    }
}

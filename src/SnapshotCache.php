<?php
declare(strict_types=1);
namespace SeoFramework;

use RuntimeException;
use Throwable;

final class SnapshotCache
{
    public string $directory;
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
        $identity = $config->file . file_get_contents($config->file) . $config->values['cacheVersion'];
        $this->directory = isset($config->values['cacheDirectory'])
            ? $config->path($config->values['cacheDirectory'])
            : sys_get_temp_dir() . '/seo-framework-' . hash('sha256', $identity);
    }

    public function read(array $seed): array
    {
        $saved = is_file($this->directory . '/snapshot.json') ? json_decode((string)@file_get_contents($this->directory . '/snapshot.json'), true) : null;
        if (is_array($saved) && is_int($saved['generatedAt'] ?? null) && is_array($saved['data'] ?? null)) {
            try { $this->config->validateData($saved['data']); return $saved; } catch (Throwable $ignored) { /* use seed */ }
        }
        return $seed;
    }

    public function get(array $seed, bool $force = false, ?callable $fetcher = null, ?int $now = null): array
    {
        $now = $now ?? time();
        $snapshot = $this->read($seed);
        $result = ['snapshot' => $snapshot, 'refreshed' => false, 'error' => null];
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            $result['error'] = 'Cache directory is unavailable.';
            return $result;
        }
        if (!$force && $now - $snapshot['generatedAt'] < $this->config->values['refreshSeconds']) return $result;
        $retry = $this->directory . '/retry.json';
        $failure = is_file($retry) ? json_decode((string)@file_get_contents($retry), true) : null;
        if (!$force && is_array($failure) && ($failure['retryAfter'] ?? 0) > $now) {
            $result['error'] = 'Refresh is in retry backoff.';
            return $result;
        }
        $lock = @fopen($this->directory . '/refresh.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock) fclose($lock);
            $result['error'] = 'Another refresh is running.';
            return $result;
        }
        try {
            // A previous writer might have finished after our first read.
            $snapshot = $this->read($seed);
            $result['snapshot'] = $snapshot;
            if (!$force && $now - $snapshot['generatedAt'] < $this->config->values['refreshSeconds']) return $result;
            $data = $fetcher ? $fetcher() : (new JsonFetcher())->fetch($this->config);
            if (!is_array($data)) throw new RuntimeException('The data adapter must return an array.');
            $this->config->validateData($data);
            $changed = $data !== $snapshot['data'];
            $replacement = ['generatedAt' => $now, 'changedAt' => $changed ? $now : ($snapshot['changedAt'] ?? $snapshot['generatedAt']), 'data' => $data];
            $this->atomicWrite($this->directory . '/snapshot.json', $replacement);
            @unlink($retry);
            $result = ['snapshot' => $replacement, 'refreshed' => true, 'error' => null];
        } catch (Throwable $error) {
            $result['error'] = $error->getMessage();
            // Do not disclose potentially sensitive adapter exception messages over HTTP.
            @file_put_contents($retry, json_encode(['retryAfter' => $now + $this->config->values['retrySeconds'], 'failedAt' => $now]));
            error_log('[seo-framework] Refresh failed; retaining last-good content: ' . $error->getMessage());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return $result;
    }

    public function atomicWrite(string $target, array $value): void
    {
        $temporary = tempnam(dirname($target), 'seo-');
        if ($temporary === false) throw new RuntimeException('Cannot create cache temporary file.');
        try {
            if (file_put_contents($temporary, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) === false || !@rename($temporary, $target)) throw new RuntimeException('Cannot atomically replace cache.');
        } finally {
            if (is_file($temporary)) unlink($temporary);
        }
    }
}

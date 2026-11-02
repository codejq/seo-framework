<?php
declare(strict_types=1);
namespace SeoFramework;

use RuntimeException;
use Throwable;

final class Application
{
    public Config $config;
    public SnapshotCache $cache;
    public Renderer $renderer;
    public array $seed;
    public string $template;

    public function __construct(string $configFile, ?string $templateOverride = null, ?string $seedOverride = null)
    {
        $this->config = new Config($configFile);
        $this->cache = new SnapshotCache($this->config);
        $this->renderer = new Renderer($this->config);
        $template = $templateOverride ?? $this->config->path($this->config->values['template'] ?? 'template.html');
        if (!is_file($template)) throw new RuntimeException('SPA template not found: ' . $template);
        $this->template = (string)file_get_contents($template);
        $seedFile = $seedOverride ?? (isset($this->config->values['seed']) ? $this->config->path($this->config->values['seed']) : null);
        if ($seedFile !== null && is_file($seedFile)) {
            $seed = json_decode((string)file_get_contents($seedFile), true, 64, JSON_THROW_ON_ERROR);
            if (!is_int($seed['generatedAt'] ?? null) || !is_array($seed['data'] ?? null)) throw new RuntimeException('Seed needs generatedAt (Unix seconds) and data.');
            $this->config->validateData($seed['data']);
            $this->seed = $seed;
        } else {
            if ($seedFile !== null) throw new RuntimeException('Configured seed file not found.');
            $this->seed = ['generatedAt' => $this->config->values['sources'] ? 0 : time(), 'data' => array_fill_keys(array_keys($this->config->values['sources']), [])];
        }
        if (PHP_SAPI !== 'cli' && !empty($_SERVER['DOCUMENT_ROOT'])) {
            $web = realpath($_SERVER['DOCUMENT_ROOT']);
            // Resolve dot segments and symlinks, including a not-yet-created cache.
            $cachePath = $this->cache->directory;
            while (!file_exists($cachePath) && dirname($cachePath) !== $cachePath) $cachePath = dirname($cachePath);
            $realCache = realpath($cachePath);
            if ($web && $realCache && ($realCache === $web || str_starts_with(str_replace('\\', '/', $realCache), str_replace('\\', '/', $web) . '/'))) throw new RuntimeException('Cache directory must be outside the document root.');
        }
    }

    public function snapshot(bool $force = false): array
    {
        return $this->cache->get($this->seed, $force);
    }

    public function status(array $snapshot): array
    {
        $age = time() - $snapshot['generatedAt'];
        return [
            'generatedAtUtc' => gmdate('c', $snapshot['generatedAt']), 'ageSeconds' => max(0, $age),
            'cache' => $age < $this->config->values['refreshSeconds'] ? 'FRESH' : 'STALE',
            'refreshSeconds' => $this->config->values['refreshSeconds'],
            'cacheDirectory' => $this->cache->directory,
            'sources' => array_keys($this->config->values['sources']),
        ];
    }

    public function serve(): void
    {
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        try {
            if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
                http_response_code(405); header('Allow: GET, HEAD'); return;
            }
            $path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
            if (preg_match('~(?:^|/)\.seo(?:/|$)|(?:^|/)seo\.php(?:/|$)~', rawurldecode($path))) {
                http_response_code(404); header('X-Robots-Tag: noindex'); return;
            }
            $result = $this->snapshot();
            $snapshot = $result['snapshot'];
            $pages = $this->config->pages($snapshot['data'], true);
            $target = $this->renderer->resolve($path, $pages);
            if ($target['kind'] === 'missing') {
                http_response_code(404); header('X-Robots-Tag: noindex');
                if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') echo 'Page not found';
                return;
            }
            if ($target['kind'] === 'spa') {
                header('X-Robots-Tag: noindex, nofollow');
                header('Content-Type: text/html; charset=UTF-8');
                if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') echo $this->template;
                return;
            }
            $status = $this->status($snapshot);
            header('X-SEO-Generated-At: ' . $status['generatedAtUtc']);
            header('X-SEO-Cache: ' . $status['cache']);
            header('X-SEO-Refresh: ' . ((empty($this->config->values['sources']) || function_exists('curl_multi_init')) && is_writable($this->cache->directory) ? 'enabled' : 'unavailable'));
            if ($target['kind'] === 'sitemap') {
                header('Content-Type: application/xml; charset=UTF-8');
                if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') echo $this->renderer->sitemap($pages);
                return;
            }
            header('Content-Type: text/html; charset=UTF-8');
            if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') echo $this->renderer->html($this->template, $pages[$target['path']], $target['language'], $pages);
        } catch (Throwable $error) {
            error_log('[seo-framework] ' . $error->getMessage());
            http_response_code(503); header('X-Robots-Tag: noindex');
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') echo 'Page temporarily unavailable';
        }
    }
}

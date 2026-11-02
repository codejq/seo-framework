<?php
declare(strict_types=1);
namespace SeoFramework;

use InvalidArgumentException;

final class Config
{
    public array $values;
    public string $file;

    public function __construct(string $file)
    {
        $this->file = realpath($file) ?: throw new InvalidArgumentException('Config file not found: ' . $file);
        $values = require $this->file;
        if (!is_array($values)) throw new InvalidArgumentException('Site config must return an array.');
        $this->values = $values + [
            'siteName' => 'My Website', 'languages' => ['en'], 'defaultLanguage' => 'en',
            'prefixLanguages' => true, 'rtlLanguages' => ['ar', 'fa', 'he', 'ur'],
            'basePath' => '', 'refreshSeconds' => 3600, 'retrySeconds' => 300,
            'sources' => [], 'pages' => [], 'spaRoutes' => [], 'includeFiles' => [],
            'pageProvider' => null, 'validateData' => null, 'cacheVersion' => '1',
        ];
        $c = &$this->values;
        $url = parse_url((string)($c['siteUrl'] ?? ''));
        if (!$url || ($url['scheme'] ?? '') !== 'https' || empty($url['host']) ||
            isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) ||
            !in_array($url['path'] ?? '', ['', '/'], true)) {
            throw new InvalidArgumentException('siteUrl must be a clean HTTPS origin, e.g. https://example.com');
        }
        $c['siteUrl'] = rtrim($c['siteUrl'], '/');
        if (!is_array($c['languages']) || !$c['languages'] || count(array_unique($c['languages'])) !== count($c['languages'])) throw new InvalidArgumentException('languages must be a nonempty unique list.');
        foreach ($c['languages'] as $language) if (!is_string($language) || !preg_match('/^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/D', $language)) throw new InvalidArgumentException('Invalid language code.');
        if (!in_array($c['defaultLanguage'], $c['languages'], true)) throw new InvalidArgumentException('defaultLanguage must be supported.');
        if (!$c['prefixLanguages'] && count($c['languages']) !== 1) throw new InvalidArgumentException('Unprefixed sites must configure exactly one language.');
        if ($c['basePath'] !== '' && (!is_string($c['basePath']) || !self::validPath($c['basePath']) || $c['basePath'] === '/')) throw new InvalidArgumentException('basePath must be empty or a path such as /shop.');
        foreach (['refreshSeconds', 'retrySeconds'] as $key) if (!is_int($c[$key]) || $c[$key] < 1) throw new InvalidArgumentException($key . ' must be a positive integer.');
        foreach (['pageProvider', 'validateData'] as $key) if ($c[$key] !== null && !is_callable($c[$key])) throw new InvalidArgumentException($key . ' must be callable.');
        foreach ($c['spaRoutes'] as $route) if (!self::validPath($route) || $route === '/') throw new InvalidArgumentException('SPA routes must be explicit non-root path prefixes.');
        foreach ($c['sources'] as $key => $source) {
            if (!is_string($key) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/D', $key) || !is_array($source)) throw new InvalidArgumentException('Invalid source definition.');
            $remote = parse_url((string)($source['url'] ?? ''));
            if (!$remote || ($remote['scheme'] ?? '') !== 'https' || empty($remote['host']) || isset($remote['user']) || isset($remote['pass']) || isset($remote['fragment'])) throw new InvalidArgumentException('Public JSON sources require HTTPS without credentials or fragments.');
            if (!in_array($source['type'] ?? 'list', ['list', 'object'], true)) throw new InvalidArgumentException('Source type must be list or object.');
        }
        $this->pages([]);
    }

    public static function validPath($path): bool
    {
        return is_string($path) && ($path === '/' || preg_match('~^/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+$~D', $path) === 1);
    }

    public function path(string $value): string
    {
        if (preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $value)) return $value;
        return dirname($this->file) . '/' . $value;
    }

    public function pages(array $data, bool $dynamic = false): array
    {
        $pages = $this->values['pages'];
        if ($dynamic && $this->values['pageProvider']) $pages = array_merge($pages, ($this->values['pageProvider'])($data, $this->values));
        $found = [];
        foreach ($pages as $page) {
            $path = $page['path'] ?? null;
            if (!self::validPath($path) || $path === '/sitemap.xml' || isset($found[$path])) throw new InvalidArgumentException('Public page paths must be unique and safe: ' . (string)$path);
            foreach ($this->values['spaRoutes'] as $private) if ($path === $private || str_starts_with($path, $private . '/')) throw new InvalidArgumentException('A public page overlaps a private SPA route: ' . $path);
            if (!is_array($page['translations'] ?? null) || !isset($page['translations'][$this->values['defaultLanguage']]['title'])) throw new InvalidArgumentException('Each page needs a default-language title.');
            $found[$path] = $page;
        }
        return $found;
    }

    public function validateData(array $data): void
    {
        foreach ($this->values['sources'] as $key => $source) {
            if (!array_key_exists($key, $data) || !is_array($data[$key])) throw new InvalidArgumentException('Missing source: ' . $key);
            if (($source['type'] ?? 'list') === 'list' && !array_is_list($data[$key])) throw new InvalidArgumentException('Source must be a list: ' . $key);
            if (($source['type'] ?? 'list') === 'object' && $data[$key] !== [] && array_is_list($data[$key])) throw new InvalidArgumentException('Source must be an object: ' . $key);
            if (($source['allowEmpty'] ?? true) === false && $data[$key] === []) throw new InvalidArgumentException('Source cannot be empty: ' . $key);
        }
        if ($this->values['validateData']) ($this->values['validateData'])($data);
        // A bad adapter must never evict the last renderable snapshot.
        $this->pages($data, true);
    }
}

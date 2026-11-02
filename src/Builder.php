<?php
declare(strict_types=1);
namespace SeoFramework;

use RuntimeException;

final class Builder
{
    private string $outputRoot = '';

    public function build(Application $app, string $output, bool $offline = false, bool $replacePolicy = false): array
    {
        if (!is_dir($output)) throw new RuntimeException('Output must be an existing SPA build directory.');
        $output = realpath($output);
        $this->outputRoot = str_replace('\\', '/', $output);
        $framework = realpath(dirname(__DIR__));
        if ($output === $framework || $output === dirname($app->config->file)) throw new RuntimeException('Use a separate SPA build directory, not the framework/config directory.');
        $policy = $output . '/.htaccess';
        if (is_file($policy) && !str_contains((string)file_get_contents($policy), '# seo-framework') && !$replacePolicy) throw new RuntimeException('Existing .htaccess found. Review and merge it, or pass --replace-htaccess explicitly.');
        if (is_link($output . '/.seo')) throw new RuntimeException('Refusing symlinked SEO output.');
        $result = $offline ? ['snapshot' => $app->seed, 'error' => null] : $app->snapshot(true);
        if (!$offline && $result['error'] !== null) throw new RuntimeException('Build refresh failed: ' . $result['error']);
        $snapshot = $result['snapshot'];
        $pages = $app->config->pages($snapshot['data'], true);
        if (!$pages) throw new RuntimeException('At least one public page is required.');
        // Render first, before publishing runtime files, to catch adapter/template errors.
        $documents = [];
        foreach ($pages as $page) foreach ($app->config->values['languages'] as $language) {
            $relative = $app->config->values['prefixLanguages'] ? $language . '/' : '';
            $relative .= $page['path'] === '/' ? 'index.html' : trim($page['path'], '/') . '/index.html';
            $documents[$relative] = $app->renderer->html($app->template, $page, $language, $pages);
        }
        $private = $output . '/.seo';
        $this->write($private . '/.htaccess', "Require all denied\n");
        $this->write($private . '/bootstrap.php', (string)file_get_contents($framework . '/bootstrap.php'));
        foreach (glob($framework . '/src/*.php') as $file) $this->write($private . '/src/' . basename($file), (string)file_get_contents($file));
        foreach ($app->config->values['includeFiles'] as $relative) {
            if (!is_string($relative) || !preg_match('~^(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+\.(?:php|json)$~D', $relative) || in_array($relative, ['config.php', 'config-source.php', 'seed.json', 'template.html', 'bootstrap.php'], true) || str_starts_with($relative, 'src/')) throw new RuntimeException('includeFiles accepts relative PHP/JSON files outside reserved framework names.');
            $source = $app->config->path($relative);
            if (!is_file($source) || is_link($source)) throw new RuntimeException('Missing or symlinked include file: ' . $relative);
            $this->write($private . '/' . $relative, (string)file_get_contents($source));
        }
        $sourceConfig = (string)file_get_contents($app->config->file);
        $this->write($private . '/config-source.php', $sourceConfig);
        $config = <<<'PHP'
<?php
declare(strict_types=1);
$site = require __DIR__ . '/config-source.php';
if (!is_array($site)) throw new \RuntimeException('Site config must return an array.');
// Resolve packaged resources and cache storage on the deployment host.
$site['template'] = 'template.html';
$site['seed'] = 'seed.json';
unset($site['cacheDirectory']);
$cacheDirectory = getenv('SEO_CACHE_DIRECTORY');
if ($cacheDirectory !== false && $cacheDirectory !== '') $site['cacheDirectory'] = $cacheDirectory;
return $site;
PHP;
        // Keep cache identity sensitive to source configuration changes.
        $this->write($private . '/config.php', $config . "\n// Source SHA-256: " . hash('sha256', $sourceConfig) . "\n");
        $this->write($private . '/template.html', $app->template);
        $this->write($private . '/seed.json', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        foreach ($documents as $relative => $html) $this->write($output . '/__seo/' . $relative, $html);
        $this->write($output . '/sitemap.xml', $app->renderer->sitemap($pages));
        $this->write($output . '/__seo/seo-client.mjs', (string)file_get_contents($framework . '/assets/seo-client.mjs'));
        $defaultHome = $app->config->values['prefixLanguages'] ? $app->config->values['defaultLanguage'] . '/index.html' : 'index.html';
        if (isset($documents[$defaultHome])) $this->write($output . '/index.html', $documents[$defaultHome]);
        $entry = <<<'PHP'
<?php
declare(strict_types=1);
require_once __DIR__ . '/.seo/bootstrap.php';
try {
    $app = new \SeoFramework\Application(__DIR__ . '/.seo/config.php', __DIR__ . '/.seo/template.html', __DIR__ . '/.seo/seed.json');
    if (PHP_SAPI === 'cli') {
        if (($argv[1] ?? 'refresh') === 'status') {
            echo json_encode($app->status($app->cache->read($app->seed)), JSON_PRETTY_PRINT) . PHP_EOL;
            exit(0);
        }
        if (($argv[1] ?? 'refresh') !== 'refresh') throw new \RuntimeException('Use: php seo.php [refresh|status]');
        $result = $app->snapshot(true);
        echo json_encode($app->status($result['snapshot']) + ['refreshed' => $result['refreshed'], 'error' => $result['error']], JSON_PRETTY_PRINT) . PHP_EOL;
        exit($result['refreshed'] ? 0 : 1);
    }
    $app->serve();
} catch (\Throwable $error) {
    error_log('[seo-framework] ' . $error->getMessage());
    if (PHP_SAPI === 'cli') { fwrite(STDERR, $error->getMessage() . PHP_EOL); exit(1); }
    http_response_code(503); header('X-Robots-Tag: noindex'); echo 'Page temporarily unavailable';
}
PHP;
        $this->write($output . '/seo.php', $entry . "\n");
        $apache = <<<'APACHE'
# seo-framework: review custom redirects/security rules before replacing this file.
DirectoryIndex index.html
<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteRule ^\.seo(?:/|$) - [F,END]
  # Existing assets pass through; missing chunks must never return HTML.
  RewriteRule ^(?:assets|static)/ - [END]
  RewriteRule ^(?:sitemap\.xml)?$ seo.php [END]
  RewriteCond %{REQUEST_FILENAME} -f
  RewriteRule ^ - [END]
  RewriteRule ^ seo.php [END]
</IfModule>
<IfModule mod_headers.c>
  <FilesMatch "^index\.html$">
    Header always set Cache-Control "no-store"
  </FilesMatch>
</IfModule>
APACHE;
        $this->write($policy, $apache . "\n");
        $manifest = ['frameworkVersion' => '0.1.0', 'siteUrl' => $app->config->values['siteUrl'], 'publicPages' => count($pages), 'documents' => count($documents), 'seedGeneratedAtUtc' => gmdate('c', $snapshot['generatedAt']), 'offline' => $offline];
        $this->write($private . '/build.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
        return $manifest;
    }

    private function write(string $path, string $content): void
    {
        $cursor = str_replace('\\', '/', $path);
        while ($cursor !== $this->outputRoot && dirname($cursor) !== $cursor) {
            if (is_link($cursor)) throw new RuntimeException('Refusing symlinked output path: ' . $cursor);
            $cursor = dirname($cursor);
        }
        if ($cursor !== $this->outputRoot) throw new RuntimeException('Output path escapes the build directory.');
        $parent = dirname($path);
        if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) throw new RuntimeException('Cannot create output directory.');
        if (file_put_contents($path, $content) === false) throw new RuntimeException('Cannot write output file: ' . $path);
    }
}

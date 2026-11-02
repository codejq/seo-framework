<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

use SeoFramework\Application;
use SeoFramework\Builder;
use SeoFramework\Config;
use SeoFramework\Renderer;

$checks = 0;
function check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++; echo "ok $message\n";
}
function rejects(callable $callback, string $message): void {
    try { $callback(); } catch (Throwable $error) { check(true, $message); return; }
    throw new RuntimeException('Expected rejection: ' . $message);
}
function cleanup(string $path): void {
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    foreach (scandir($path) as $entry) if ($entry !== '.' && $entry !== '..') cleanup($path . '/' . $entry);
    rmdir($path);
}
$temp = sys_get_temp_dir() . '/seo-framework-tests-' . bin2hex(random_bytes(8));
mkdir($temp, 0700);
$root = dirname(__DIR__);
try {
    $base = require $root . '/examples/basic/site.php';
    $base['template'] = $root . '/examples/basic/public/index.html';
    $base['cacheDirectory'] = $temp . '/cache';
    $base['sources'] = ['catalog' => ['url' => 'https://api.example.com/catalog', 'type' => 'list']];
    $file = $temp . '/site.php';
    file_put_contents($file, '<?php return ' . var_export($base, true) . ';');
    $app = new Application($file);
    $seed = ['generatedAt' => 100, 'changedAt' => 100, 'data' => ['catalog' => [['name' => 'Old']]]];
    $calls = 0;
    $fetch = static function () use (&$calls): array { $calls++; return ['catalog' => [['name' => 'New']]]; };
    check($app->cache->get($seed, false, $fetch, 101)['snapshot'] === $seed && $calls === 0, 'fresh seed avoids API calls');
    $snapshot = $app->cache->get($seed, false, $fetch, 3701)['snapshot'];
    check($snapshot['data']['catalog'][0]['name'] === 'New' && $calls === 1, 'expired snapshots refresh');
    check($app->cache->get($seed, false, $fetch, 3800)['snapshot'] === $snapshot && $calls === 1, 'cache persists across requests');
    $bad = static function (): array { throw new RuntimeException('Simulated outage'); };
    $failed = $app->cache->get($seed, false, $bad, 7400);
    check($failed['snapshot'] === $snapshot && $failed['error'] !== null, 'API outage retains last-good data');
    check($app->cache->get($seed, false, $fetch, 7401)['snapshot'] === $snapshot && $calls === 1, 'failed fetches use retry backoff');
    $lock = fopen($app->cache->directory . '/refresh.lock', 'c'); flock($lock, LOCK_EX);
    $locked = $app->cache->get($seed, true, $fetch, 7800);
    check(!$locked['refreshed'] && $locked['snapshot'] === $snapshot, 'concurrent refresh never blocks visitors');
    flock($lock, LOCK_UN); fclose($lock);
    check($app->cache->get($seed, true, $fetch, 7801)['refreshed'] && $calls === 2, 'CLI can bypass TTL and backoff');
    check($app->cache->get($seed, true, static fn() => ['wrong' => []], 7900)['snapshot']['generatedAt'] === 7801, 'incomplete responses never replace snapshots');
    check($app->cache->get($seed, true, static fn() => ['catalog' => ['error' => 'bad']], 7901)['snapshot']['generatedAt'] === 7801, 'object payload rejected for list source');
    $empty = $app->cache->get($seed, true, static fn() => ['catalog' => []], 8000);
    check($empty['refreshed'] && $empty['snapshot']['data']['catalog'] === [], 'valid empty lists remove deleted content');
    $app->config->values['pageProvider'] = static function (): array { return [['path' => '/account', 'translations' => ['en' => ['title' => 'Leak']]]]; };
    $app->cache->get($seed, true, $fetch, 8100);
    check(json_decode(file_get_contents($app->cache->directory . '/snapshot.json'), true) === $empty['snapshot'], 'adapter cannot replace snapshot with private routes');
    $app->config->values['pageProvider'] = null;
    file_put_contents($app->cache->directory . '/snapshot.json', '{broken');
    check($app->cache->read($seed) === $seed, 'corrupt persisted cache falls back to seed');
    rejects(static fn() => $app->cache->atomicWrite($app->cache->directory . '/snapshot.json', ['value' => INF]), 'failed atomic writes report failure');
    check($app->cache->read($seed) === $seed, 'failed writes do not invent a refreshed snapshot');

    $pages = $app->config->pages([]);
    $page = $pages['/'];
    $page['translations']['en']['title'] = 'Hello <script>bad()</script> <b>world</b>';
    $page['translations']['en']['sections'] = [['heading' => '<img src=x onerror=bad()>Safe', 'links' => [['url' => 'javascript:alert(1)', 'label' => 'Unsafe'], ['url' => '//evil.test', 'label' => 'Unsafe'], ['url' => '/en/about', 'label' => 'About']]]];
    $page['translations']['en']['structuredData'] = ['name' => '</script><script>bad()</script>'];
    $html = $app->renderer->html($app->template, $page, 'en', $pages);
    check(str_contains($html, '<h1>Hello world</h1>') && !str_contains($html, 'javascript:'), 'text and link escaping prevent executable API markup');
    check(!str_contains($html, 'onerror=') && !str_contains($html, '<script>bad()'), 'structured data cannot break out of script');
    check(str_contains($html, 'src="/assets/app.js"') && str_contains($html, 'id="app"'), 'SPA assets and configurable mount root are preserved');
    check(str_contains($html, 'class="studio-theme"'), 'existing HTML attributes survive');
    check(substr_count($html, 'name="description"') === 1 && substr_count($html, '<title>') === 1, 'metadata appears once');
    check(str_contains($html, 'hreflang="x-default"') && str_contains($html, 'application/ld+json'), 'canonical alternates and structured data emitted');
    check(str_contains($app->renderer->html($app->template, $pages['/'], 'ar', $pages), 'dir="rtl"'), 'Arabic gets RTL and translated content');
    rejects(static fn() => $app->renderer->html($html, $page, 'en', $pages), 'previously rendered template rejected');
    $emptyRoot = '<html><head></head><body><app-root id=\'custom\' class="app"></app-root></body></html>';
    $app->config->values['rootId'] = 'custom';
    check(str_contains($app->renderer->html($emptyRoot, $page, 'en', $pages), 'data-seo-framework-body'), 'custom elements and single-quoted root IDs supported');
    $app->config->values['rootId'] = 'app';
    check($app->renderer->resolve('/en/about?bad', $pages)['kind'] === 'missing', 'unrecognized route rejected');
    check($app->renderer->resolve('/en/about/', $pages)['kind'] === 'page', 'trailing slash routes normalized');
    check($app->renderer->resolve('/en/account/settings', $pages)['kind'] === 'spa', 'private route prefixes stay SPA-only');
    check($app->renderer->resolve('/xx/about', $pages)['kind'] === 'missing', 'unknown languages rejected');
    check($app->renderer->resolve('/en/../about', $pages)['kind'] === 'missing', 'traversal routes rejected');
    $app->config->values['basePath'] = '/shop';
    check($app->renderer->resolve('/shop/ar/about', $pages)['kind'] === 'page' && $app->renderer->url('ar', '/about') === 'https://example.com/shop/ar/about', 'subdirectory hosting supported');
    $app->config->values['basePath'] = '';
    $xml = $app->renderer->sitemap($pages);
    check(substr_count($xml, '<loc>') === 4 && !str_contains($xml, '/account'), 'sitemap includes only localized public pages');
    if (function_exists('simplexml_load_string')) check(simplexml_load_string($xml) !== false, 'sitemap is valid XML');

    $catalog = new Application($root . '/examples/catalog/site.php');
    $dynamic = $catalog->config->pages($catalog->seed['data'], true);
    check(isset($dynamic['/products/desk-lamp']), 'API adapter discovers dynamic pages');
    check(str_contains($catalog->renderer->html($catalog->template, $dynamic['/products/desk-lamp'], 'ar', $dynamic), 'مصباح مكتب'), 'dynamic pages render localized text');
    check(count($catalog->config->pages(['products' => []], true)) === 1, 'deleted products disappear from pages and sitemap');
    rejects(static fn() => $catalog->config->validateData(['products' => [['slug' => '../secret']]]), 'bad API record rejected before caching');

    $output = $temp . '/dist'; mkdir($output);
    file_put_contents($output . '/.htaccess', '# custom rules');
    rejects(static fn() => (new Builder())->build($catalog, $output, true), 'builder protects existing rewrite policy');
    $manifest = (new Builder())->build($catalog, $output, true, true);
    check($manifest['documents'] === 4 && is_file($output . '/seo.php'), 'build packages dynamic localized fallbacks');
    check(str_contains(file_get_contents($output . '/.seo/.htaccess'), 'Require all denied'), 'private package has deny policy');
    check(str_contains(file_get_contents($output . '/.htaccess'), '^\.seo(?:/|$) - [F,END]'), 'Apache protects private config');
    $packaged = new Application($output . '/.seo/config.php', $output . '/.seo/template.html', $output . '/.seo/seed.json');
    check(count($packaged->config->pages($packaged->seed['data'], true)) === 2, 'packaged adapters resolve independently of source tree');
    $manifest2 = (new Builder())->build($catalog, $output, true);
    check($manifest2['documents'] === 4, 'build can run repeatedly without nesting HTML');
    foreach ([['siteUrl' => 'http://example.com'], ['languages' => ['en', 'en']], ['defaultLanguage' => 'xx'], ['prefixLanguages' => false], ['basePath' => '/../outside'], ['sources' => ['bad' => ['url' => 'http://internal.test']]]] as $override) {
        file_put_contents($temp . '/invalid.php', '<?php return ' . var_export(array_replace($base, $override), true) . ';');
        rejects(static fn() => new Config($temp . '/invalid.php'), 'invalid site configuration rejected');
    }
    $single = $base; $single['languages'] = ['en']; $single['prefixLanguages'] = false;
    file_put_contents($temp . '/single.php', '<?php return ' . var_export($single, true) . ';');
    $singleApp = new Application($temp . '/single.php');
    check($singleApp->renderer->resolve('/about', $singleApp->config->pages([]))['kind'] === 'page' && $singleApp->renderer->url('en', '/') === 'https://example.com/', 'single-language sites need no language prefix');
    echo "$checks framework checks passed\n";
} finally {
    cleanup($temp);
}

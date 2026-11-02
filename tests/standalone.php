<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

use SeoFramework\Application;
use SeoFramework\Builder;

$checks = 0;
function verifyStandalone(bool $condition, string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    $checks++; echo "ok $label\n";
}
function removeStandalone(string $path): void {
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    foreach (scandir($path) as $name) if ($name !== '.' && $name !== '..') removeStandalone($path . '/' . $name);
    rmdir($path);
}
function runStandalone(array $arguments, string $cwd): array {
    $process = proc_open(array_merge([PHP_BINARY], $arguments), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (!is_resource($process)) throw new RuntimeException('Cannot launch standalone PHP process.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $code = proc_close($process);
    if ($code !== 0 || $stderr !== '') throw new RuntimeException('Standalone process failed: ' . $stderr . $stdout);
    return json_decode($stdout, true, 64, JSON_THROW_ON_ERROR);
}

$temporary = sys_get_temp_dir() . '/seo-framework-standalone-' . bin2hex(random_bytes(8));
$previousCache = getenv('SEO_CACHE_DIRECTORY');
mkdir($temporary, 0700);
try {
    putenv('SEO_CACHE_DIRECTORY');
    $source = $temporary . '/source';
    mkdir($source . '/adapters', 0755, true);
    mkdir($source . '/public', 0755, true);
    $catalog = dirname(__DIR__) . '/examples/catalog';
    foreach (['adapters/products.php', 'public/index.html', 'seed.json'] as $file) copy($catalog . '/' . $file, $source . '/' . $file);
    $config = str_replace(
        ["'template' => 'public/index.html'", "'seed' => 'seed.json'", "'rootId' => 'app'"],
        ["'template' => __DIR__ . '/public/index.html'", "'seed' => __DIR__ . '/seed.json'", "'rootId' => 'app', 'cacheDirectory' => __DIR__ . '/build-cache'"],
        (string)file_get_contents($catalog . '/site.php')
    );
    file_put_contents($source . '/site.php', $config);
    mkdir($temporary . '/staging');
    (new Builder())->build(new Application($source . '/site.php'), $temporary . '/staging', true);
    // Remove all build inputs and change the deployment path before starting a fresh PHP process.
    removeStandalone($source);
    $deployment = $temporary . '/relocated';
    rename($temporary . '/staging', $deployment);
    $status = runStandalone([$deployment . '/seo.php', 'status'], $temporary);
    verifyStandalone($status['sources'] === ['products'], 'relocated CLI loads bundled config and adapters after source removal');
    verifyStandalone(!str_contains($status['cacheDirectory'], 'build-cache'), 'deployment does not inherit build-machine cache storage');

    $render = <<<'PHP'
require $argv[1] . '/.seo/bootstrap.php';
$app = new \SeoFramework\Application($argv[1] . '/.seo/config.php');
$pages = $app->config->pages($app->seed['data'], true);
echo json_encode([
    'html' => $app->renderer->html($app->template, $pages['/products/desk-lamp'], 'ar', $pages),
    'sitemap' => $app->renderer->sitemap($pages),
]);
PHP;
    $result = runStandalone(['-r', $render, $deployment], $temporary);
    verifyStandalone(str_contains($result['html'], 'مصباح مكتب') && str_contains($result['html'], 'dir="rtl"'), 'relocated package renders localized catalog from its own template and seed');
    verifyStandalone(substr_count($result['sitemap'], '<loc>') === 4, 'relocated package generates all localized sitemap entries');
    verifyStandalone(!str_contains($result['html'], '/assets/app.js'), 'catalog has no dependency on another example frontend');

    $targetCache = $temporary . '/host-cache';
    putenv('SEO_CACHE_DIRECTORY=' . $targetCache);
    $status = runStandalone([$deployment . '/seo.php', 'status'], $temporary);
    verifyStandalone($status['cacheDirectory'] === $targetCache, 'deployment host can select its own private cache directory');
    echo "$checks standalone checks passed\n";
} finally {
    putenv($previousCache === false ? 'SEO_CACHE_DIRECTORY' : 'SEO_CACHE_DIRECTORY=' . $previousCache);
    removeStandalone($temporary);
}

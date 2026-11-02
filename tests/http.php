<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

use SeoFramework\Application;
use SeoFramework\Builder;

$checks = 0;
function verifyHttp(bool $condition, string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    $checks++; echo "ok $label\n";
}
function removeHttpTest(string $path): void {
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    foreach (scandir($path) as $name) if ($name !== '.' && $name !== '..') removeHttpTest($path . '/' . $name);
    rmdir($path);
}
$temporary = sys_get_temp_dir() . '/seo-framework-http-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
$server = null;
$root = dirname(__DIR__);
$previousCache = getenv('SEO_CACHE_DIRECTORY');
try {
    putenv('SEO_CACHE_DIRECTORY=' . $temporary . '/host-cache');
    $site = require $root . '/examples/basic/site.php';
    $site['template'] = $root . '/examples/basic/public/index.html';
    $site['cacheDirectory'] = $temporary . '/private-cache';
    file_put_contents($temporary . '/site.php', '<?php return ' . var_export($site, true) . ';');
    mkdir($temporary . '/dist/assets', 0755, true);
    copy($root . '/examples/basic/public/assets/app.js', $temporary . '/dist/assets/app.js');
    (new Builder())->build(new Application($temporary . '/site.php'), $temporary . '/dist');
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$socket) throw new RuntimeException('Cannot reserve test server port.');
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $server = proc_open([PHP_BINARY, '-S', $address, '-t', $temporary . '/dist', $root . '/tools/router.php'], [0 => ['pipe', 'r'], 1 => ['file', $temporary . '/server.log', 'a'], 2 => ['file', $temporary . '/server.log', 'a']], $pipes);
    if (!is_resource($server)) throw new RuntimeException('Cannot launch PHP HTTP test server.');
    fclose($pipes[0]);
    $ready = false;
    for ($i = 0; $i < 100; $i++) {
        $probe = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if ($probe) { fclose($probe); $ready = true; break; }
        usleep(50000);
    }
    if (!$ready) throw new RuntimeException('HTTP server failed to start: ' . file_get_contents($temporary . '/server.log'));
    $request = static function (string $path, string $method = 'GET') use ($address): array {
        $context = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true, 'timeout' => 10, 'header' => "Connection: close\r\n"]]);
        $stream = fopen('http://' . $address . $path, 'r', false, $context);
        if (!$stream) throw new RuntimeException('HTTP request failed.');
        $body = stream_get_contents($stream);
        $headers = stream_get_meta_data($stream)['wrapper_data']; fclose($stream);
        preg_match('/\s(\d{3})\s/', $headers[0], $status);
        $named = [];
        foreach ($headers as $header) if (str_contains($header, ':')) { [$name, $value] = explode(':', $header, 2); $named[strtolower($name)] = trim($value); }
        return ['status' => (int)$status[1], 'headers' => $named, 'body' => $body];
    };
    $home = $request('/en');
    verifyHttp($home['status'] === 200 && str_contains($home['body'], 'data-seo-framework-body'), 'public HTML is present before JavaScript');
    verifyHttp($home['headers']['x-seo-cache'] === 'FRESH' && $home['headers']['x-seo-refresh'] === 'enabled', 'cache status headers reflect a usable snapshot');
    $again = $request('/en?cache-check=1');
    verifyHttp($home['headers']['x-seo-generated-at'] === $again['headers']['x-seo-generated-at'] && $home['body'] === $again['body'], 'repeat requests reuse snapshot regardless of query string');
    $arabic = $request('/ar/about');
    verifyHttp(str_contains($arabic['body'], 'dir="rtl"') && str_contains($arabic['body'], 'عن الاستوديو'), 'Arabic source is localized with RTL');
    verifyHttp($request('/en/about', 'HEAD')['body'] === '', 'HEAD responses have no body');
    verifyHttp($request('/en', 'POST')['status'] === 405, 'unsupported methods rejected');
    verifyHttp($request('/seo.php')['status'] === 404, 'direct HTTP refresh entry is unavailable');
    verifyHttp($request('/.seo/config.php')['status'] === 403, 'private config denied');
    verifyHttp($request('/assets/missing.js')['status'] === 404, 'missing chunks return 404');
    verifyHttp($request('/assets/app.js')['status'] === 200 && $request('/__seo/seo-client.mjs')['status'] === 200, 'SPA assets and optional metadata helper served');
    $private = $request('/en/account/settings');
    verifyHttp($private['status'] === 200 && $private['headers']['x-robots-tag'] === 'noindex, nofollow' && !str_contains($private['body'], 'data-seo-framework-body'), 'private routes receive clean SPA template with noindex');
    verifyHttp($request('/en/not-a-page')['status'] === 404, 'unknown public paths return 404');
    $sitemap = $request('/sitemap.xml');
    verifyHttp($sitemap['status'] === 200 && substr_count($sitemap['body'], '<loc>') === 4, 'sitemap contains exactly the public localized routes');
    verifyHttp(!str_contains($home['body'], 'Warning:') && !str_contains(file_get_contents($temporary . '/server.log'), 'Fatal error'), 'runtime emits no PHP warnings or fatal errors');
    foreach (['status', 'refresh'] as $command) {
        $process = proc_open([PHP_BINARY, $temporary . '/dist/seo.php', $command], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $cliPipes);
        fclose($cliPipes[0]);
        $stdout = stream_get_contents($cliPipes[1]); fclose($cliPipes[1]);
        $stderr = stream_get_contents($cliPipes[2]); fclose($cliPipes[2]);
        $code = proc_close($process);
        $decoded = json_decode($stdout, true);
        verifyHttp($code === 0 && is_array($decoded) && $stderr === '' && ($command !== 'refresh' || ($decoded['refreshed'] ?? false)), "packaged CLI $command works independently");
    }
    echo "$checks HTTP checks passed\n";
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    putenv($previousCache === false ? 'SEO_CACHE_DIRECTORY' : 'SEO_CACHE_DIRECTORY=' . $previousCache);
    removeHttpTest($temporary);
}

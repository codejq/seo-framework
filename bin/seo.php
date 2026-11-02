#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

use SeoFramework\Application;
use SeoFramework\Builder;

try {
    $command = $argv[1] ?? 'help';
    if (!in_array($command, ['validate', 'refresh', 'status', 'build'], true)) {
        echo "SEO Framework 0.1.0\n\n";
        echo "php bin/seo.php validate <site-config.php>\n";
        echo "php bin/seo.php refresh  <site-config.php>\n";
        echo "php bin/seo.php status   <site-config.php>\n";
        echo "php bin/seo.php build    <site-config.php> <spa-build-directory> [--offline] [--replace-htaccess]\n";
        exit(in_array($command, ['help', '--help', '-h'], true) ? 0 : 1);
    }
    if (!isset($argv[2])) throw new RuntimeException('A site config file is required.');
    $app = new Application($argv[2]);
    if ($command === 'build') {
        if (!isset($argv[3])) throw new RuntimeException('A SPA build directory is required.');
        $result = (new Builder())->build($app, $argv[3], in_array('--offline', $argv, true), in_array('--replace-htaccess', $argv, true));
    } elseif ($command === 'refresh') {
        $refresh = $app->snapshot(true);
        $result = $app->status($refresh['snapshot']) + ['refreshed' => $refresh['refreshed'], 'error' => $refresh['error']];
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit($refresh['refreshed'] ? 0 : 1);
    } elseif ($command === 'status') {
        $result = $app->status($app->cache->read($app->seed));
    } else {
        $pages = $app->config->pages($app->seed['data'], true);
        foreach ($pages as $page) foreach ($app->config->values['languages'] as $language) $app->renderer->html($app->template, $page, $language, $pages);
        $result = ['valid' => true, 'publicPages' => count($pages), 'languages' => $app->config->values['languages']];
    }
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, '[seo-framework] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
$example = $argv[1] ?? 'basic';
if (!in_array($example, ['basic', 'catalog'], true)) {
    fwrite(STDERR, "Use: php tools/build-example.php [basic|catalog]\n");
    exit(1);
}
$base = dirname(__DIR__) . '/examples/' . $example;
$output = $base . '/dist';
if (!is_dir($output)) mkdir($output, 0755, true);
if ($example === 'basic') {
    if (!is_dir($output . '/assets')) mkdir($output . '/assets', 0755, true);
    copy($base . '/public/assets/app.js', $output . '/assets/app.js');
}
$app = new \SeoFramework\Application($base . '/site.php');
echo json_encode((new \SeoFramework\Builder())->build($app, $output, $example === 'catalog'), JSON_PRETTY_PRINT) . PHP_EOL;

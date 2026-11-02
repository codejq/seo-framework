<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'SeoFramework\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $name = substr($class, strlen($prefix));
    if (preg_match('/^[A-Za-z]+$/D', $name)) {
        $file = __DIR__ . '/src/' . $name . '.php';
        if (is_file($file)) require_once $file;
    }
});

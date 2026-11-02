<?php
// Local development only. Apache/LiteSpeed uses the generated .htaccess.
declare(strict_types=1);
$root = realpath($_SERVER['DOCUMENT_ROOT']);
$path = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if (preg_match('~^/\.seo(?:/|$)~', $path)) { http_response_code(403); return true; }
$file = realpath($root . '/' . ltrim($path, '/'));
if ($file && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_file($file) && !str_ends_with($file, '/seo.php') && basename($file) !== 'seo.php') return false;
if (preg_match('~^/(?:assets|static)/~', $path)) { http_response_code(404); return true; }
require $root . '/seo.php';

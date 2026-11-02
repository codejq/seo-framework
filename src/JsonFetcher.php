<?php
declare(strict_types=1);
namespace SeoFramework;

use RuntimeException;

final class JsonFetcher
{
    public function fetch(Config $config): array
    {
        if (!$config->values['sources']) return [];
        if (!function_exists('curl_multi_init')) throw new RuntimeException('PHP cURL is required for JSON sources.');
        $multi = curl_multi_init();
        $handles = []; $bodies = [];
        try {
            foreach ($config->values['sources'] as $key => $source) {
                $bodies[$key] = '';
                $handle = curl_init($source['url']);
                $options = [
                    CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 8,
                    CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_HTTPHEADER => ['Accept: application/json'],
                    CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$bodies, $key): int {
                        if (strlen($bodies[$key]) + strlen($chunk) > 4 * 1024 * 1024) return 0;
                        $bodies[$key] .= $chunk;
                        return strlen($chunk);
                    },
                ];
                // PROTOCOLS_STR avoids the deprecated integer option on PHP 8.5.
                if (defined('CURLOPT_PROTOCOLS_STR')) $options[constant('CURLOPT_PROTOCOLS_STR')] = 'HTTPS';
                else $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
                curl_setopt_array($handle, $options);
                curl_multi_add_handle($multi, $handle);
                $handles[$key] = $handle;
            }
            do {
                $status = curl_multi_exec($multi, $running);
                if ($running && $status === CURLM_OK && curl_multi_select($multi, 0.2) === -1) usleep(10000);
            } while ($running && $status === CURLM_OK);
            if ($status !== CURLM_OK) throw new RuntimeException('Parallel JSON fetch failed.');
            $data = [];
            foreach ($handles as $key => $handle) {
                if (curl_errno($handle) || curl_getinfo($handle, CURLINFO_HTTP_CODE) !== 200) {
                    $detail = curl_errno($handle) ? curl_error($handle) : 'HTTP ' . curl_getinfo($handle, CURLINFO_HTTP_CODE);
                    throw new RuntimeException('Public source unavailable: ' . $key . ' (' . $detail . ')');
                }
                $value = json_decode($bodies[$key], true, 64, JSON_THROW_ON_ERROR);
                foreach (explode('.', $config->values['sources'][$key]['dataPath'] ?? '') as $segment) {
                    if ($segment === '') continue;
                    if (!is_array($value) || !array_key_exists($segment, $value)) throw new RuntimeException('Missing dataPath for source: ' . $key);
                    $value = $value[$segment];
                }
                $data[$key] = $value;
            }
            $config->validateData($data);
            return $data;
        } finally {
            foreach ($handles as $handle) curl_multi_remove_handle($multi, $handle);
            // Curl handles are objects; releasing references works on PHP 8.1–8.5.
            $handles = [];
            $multi = null;
        }
    }
}

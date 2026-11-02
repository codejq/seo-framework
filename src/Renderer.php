<?php
declare(strict_types=1);
namespace SeoFramework;

use RuntimeException;

final class Renderer
{
    public function __construct(private Config $config) {}

    public static function escape($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function text($value): string
    {
        $stripped = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', '', (string)$value);
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($stripped), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }

    public function url(string $language, string $path): string
    {
        $c = $this->config->values;
        return $c['siteUrl'] . $c['basePath'] . ($c['prefixLanguages'] ? '/' . $language : '') . ($path === '/' ? ($c['prefixLanguages'] ? '' : '/') : $path);
    }

    public function translation(array $page, string $language): array
    {
        return ($page['translations'][$language] ?? []) + $page['translations'][$this->config->values['defaultLanguage']];
    }

    public function resolve(string $requestPath, array $pages): array
    {
        $c = $this->config->values;
        $path = rawurldecode($requestPath);
        if (str_contains($path, "\0") || str_contains($path, '\\') || str_contains($path, '//')) return ['kind' => 'missing'];
        if ($c['basePath'] !== '') {
            if ($path !== $c['basePath'] && !str_starts_with($path, $c['basePath'] . '/')) return ['kind' => 'missing'];
            $path = substr($path, strlen($c['basePath'])) ?: '/';
        }
        if ($path === '/sitemap.xml') return ['kind' => 'sitemap'];
        $language = $c['defaultLanguage'];
        $path = '/' . trim($path, '/');
        if ($c['prefixLanguages'] && $path !== '/') {
            $parts = explode('/', trim($path, '/'));
            $language = array_shift($parts);
            if (!in_array($language, $c['languages'], true)) return ['kind' => 'missing'];
            $path = '/' . implode('/', $parts);
        }
        if (isset($pages[$path])) return ['kind' => 'page', 'path' => $path, 'language' => $language];
        foreach ($c['spaRoutes'] as $private) if ($path === $private || str_starts_with($path, $private . '/')) return ['kind' => 'spa'];
        return ['kind' => 'missing'];
    }

    private function safeLink(string $url): string
    {
        // No javascript:, data:, protocol-relative or control-character URLs.
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $url)) return '';
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) return $url;
        $parts = parse_url($url);
        if ($parts && ($parts['scheme'] ?? '') === 'https' && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass'])) return $url;
        return '';
    }

    private function sections(array $sections): string
    {
        $html = '';
        foreach ($sections as $section) {
            $html .= "<section>\n";
            if (isset($section['heading'])) $html .= '<h2>' . self::escape(self::text($section['heading'])) . "</h2>\n";
            foreach ((array)($section['text'] ?? []) as $paragraph) $html .= '<p>' . self::escape(self::text($paragraph)) . "</p>\n";
            if (!empty($section['items'])) {
                $html .= "<ul>\n";
                foreach ($section['items'] as $item) $html .= '<li>' . self::escape(self::text($item)) . "</li>\n";
                $html .= "</ul>\n";
            }
            foreach ($section['links'] ?? [] as $link) {
                $url = $this->safeLink((string)($link['url'] ?? ''));
                if ($url !== '') $html .= '<a href="' . self::escape($url) . '">' . self::escape(self::text($link['label'] ?? $url)) . "</a>\n";
            }
            $html .= "</section>\n";
        }
        return $html;
    }

    public function html(string $template, array $page, string $language, array $pages): string
    {
        if (str_contains($template, 'data-seo-framework-body')) throw new RuntimeException('Use the clean SPA template, not previously rendered SEO HTML.');
        $c = $this->config->values;
        $copy = $this->translation($page, $language);
        $title = self::text($copy['title']);
        $fullTitle = $title === $c['siteName'] ? $title : $title . ' | ' . $c['siteName'];
        $description = self::text($copy['description'] ?? '');
        $url = $this->url($language, $page['path']);
        $head = [
            '<title>' . self::escape($fullTitle) . '</title>',
            '<meta name="description" content="' . self::escape($description) . '">',
            '<meta name="robots" content="index,follow,max-image-preview:large">',
            '<link rel="canonical" href="' . self::escape($url) . '">',
        ];
        foreach (array_merge($c['languages'], ['x-default']) as $alternate) {
            $head[] = '<link rel="alternate" data-seo-framework-hreflang="' . $alternate . '" hreflang="' . $alternate . '" href="' . self::escape($this->url($alternate === 'x-default' ? $c['defaultLanguage'] : $alternate, $page['path'])) . '">';
        }
        foreach (['og:title' => $fullTitle, 'og:description' => $description, 'og:url' => $url, 'og:type' => $page['ogType'] ?? 'website', 'og:site_name' => $c['siteName'], 'og:locale' => str_replace('-', '_', $language)] as $key => $value) $head[] = '<meta property="' . $key . '" content="' . self::escape($value) . '">';
        foreach (['card' => 'summary_large_image', 'title' => $fullTitle, 'description' => $description] as $key => $value) $head[] = '<meta name="twitter:' . $key . '" content="' . self::escape($value) . '">';
        $image = $this->safeLink((string)($copy['image'] ?? $c['image'] ?? ''));
        if ($image !== '') {
            if ($image[0] === '/') $image = $c['siteUrl'] . $image;
            $head[] = '<meta property="og:image" content="' . self::escape($image) . '">';
            $head[] = '<meta name="twitter:image" content="' . self::escape($image) . '">';
        }
        $schema = ($copy['structuredData'] ?? []) + ['@context' => 'https://schema.org', '@type' => $page['schemaType'] ?? ($page['path'] === '/' ? 'WebSite' : 'WebPage'), 'name' => $title, 'description' => $description, 'url' => $url, 'inLanguage' => $language];
        $head[] = '<script type="application/ld+json" data-seo-framework>' . json_encode($schema, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) . '</script>';
        $head[] = '<style data-seo-framework>.seo-framework{font:18px/1.7 system-ui,sans-serif;max-width:1100px;margin:auto;padding:24px}.seo-framework nav{display:flex;gap:16px;flex-wrap:wrap}.seo-framework a{color:#175b85}</style>';
        $body = '<div class="seo-framework" data-seo-framework-body>' . "\n<header>\n<nav aria-label=\"Site\">\n";
        foreach ($pages as $item) {
            if (($item['navigation'] ?? true) === false) continue;
            $nav = $this->translation($item, $language);
            $body .= '<a href="' . self::escape($this->url($language, $item['path'])) . '">' . self::escape(self::text($nav['nav'] ?? $nav['title'])) . "</a>\n";
        }
        $body .= "</nav>\n</header>\n<main>\n<h1>" . self::escape($title) . "</h1>\n<p>" . self::escape($description) . "</p>\n" . $this->sections($copy['sections'] ?? []) . "</main>\n</div>";
        $direction = in_array($language, $c['rtlLanguages'], true) ? 'rtl' : 'ltr';
        // Preserve template attributes such as a theme class; replace only lang/dir.
        $template = preg_replace_callback('/<html\b([^>]*)>/i', static function ($m) use ($language, $direction) {
            $attrs = preg_replace('/\s+(?:lang|dir)\s*=\s*(?:"[^"]*"|\x27[^\x27]*\x27|[^\s>]+)/i', '', $m[1]);
            return '<html lang="' . $language . '" dir="' . $direction . '"' . $attrs . '>';
        }, $template);
        $template = preg_replace('~<title\b[^>]*>.*?</title>~is', '', $template);
        $template = preg_replace('~<meta\b(?=[^>]*(?:name\s*=\s*["\x27](?:description|robots|twitter:[^"\x27]*)["\x27]|property\s*=\s*["\x27]og:[^"\x27]*["\x27]))[^>]*>~i', '', $template);
        $template = preg_replace('~<link\b(?=[^>]*rel\s*=\s*["\x27](?:canonical|alternate)["\x27])[^>]*>~i', '', $template);
        $headHtml = implode("\n", $head) . "\n";
        if (str_contains($template, '<!-- SEO:HEAD -->')) $template = str_replace('<!-- SEO:HEAD -->', $headHtml, $template);
        elseif (preg_match('~</head>~i', $template)) $template = preg_replace_callback('~</head>~i', static fn() => $headHtml . '</head>', $template, 1);
        else throw new RuntimeException('Template needs </head> or <!-- SEO:HEAD -->.');
        if (substr_count($template, '<!-- SEO:BODY -->') === 1) return str_replace('<!-- SEO:BODY -->', $body, $template);
        $root = preg_quote($c['rootId'] ?? 'root', '~');
        $count = 0;
        $template = preg_replace_callback('~(<([a-z][a-z0-9-]*)\b[^>]*\bid\s*=\s*["\x27]' . $root . '["\x27][^>]*>)\s*</\2>~i', static fn($m) => $m[1] . "\n" . $body . "\n</" . $m[2] . '>', $template, 1, $count);
        if ($count !== 1) throw new RuntimeException('Template needs an empty SPA root or one <!-- SEO:BODY --> marker inside that root.');
        return $template;
    }

    public function sitemap(array $pages): string
    {
        $xml = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">'];
        foreach ($pages as $page) foreach ($this->config->values['languages'] as $language) {
            $xml[] = '<url><loc>' . self::escape($this->url($language, $page['path'])) . '</loc>';
            foreach (array_merge($this->config->values['languages'], ['x-default']) as $alternate) $xml[] = '<xhtml:link rel="alternate" hreflang="' . $alternate . '" href="' . self::escape($this->url($alternate === 'x-default' ? $this->config->values['defaultLanguage'] : $alternate, $page['path'])) . '"/>';
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $page['lastModified'] ?? '')) $xml[] = '<lastmod>' . $page['lastModified'] . '</lastmod>';
            $xml[] = '</url>';
        }
        $xml[] = '</urlset>';
        return implode("\n", $xml) . "\n";
    }
}

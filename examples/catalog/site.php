<?php
declare(strict_types=1);

return [
    'siteUrl' => 'https://example.com',
    'siteName' => 'Example Catalog',
    'template' => 'public/index.html',
    'rootId' => 'app',
    'languages' => ['en', 'ar'],
    'seed' => 'seed.json',
    // Replace this placeholder with your own anonymous, public API endpoint.
    'sources' => ['products' => [
        'url' => 'https://api.example.com/public/products',
        'dataPath' => 'data.items',
        'type' => 'list',
        'allowEmpty' => true,
    ]],
    'includeFiles' => ['adapters/products.php'],
    'pageProvider' => require __DIR__ . '/adapters/products.php',
    'validateData' => static function (array $data): void {
        foreach ($data['products'] as $product) {
            if (!is_array($product) || !preg_match('/^[a-z0-9-]+$/D', $product['slug'] ?? '') || !is_numeric($product['price'] ?? null) || $product['price'] < 0 || !isset($product['name']['en'])) throw new \RuntimeException('Invalid product data.');
        }
    },
    'spaRoutes' => ['/account', '/checkout'],
    'pages' => [
        ['path' => '/', 'translations' => [
            'en' => ['title' => 'Our catalog', 'description' => 'Explore our current products.'],
            'ar' => ['title' => 'منتجاتنا', 'description' => 'اكتشف منتجاتنا الحالية.'],
        ]],
    ],
];

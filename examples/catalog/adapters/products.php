<?php
declare(strict_types=1);

// Adapters receive complete public snapshots; never read accounts or cookies.
return static function (array $data, array $site): array {
    $pages = [];
    foreach ($data['products'] ?? [] as $product) {
        $page = ['path' => '/products/' . $product['slug'], 'navigation' => true, 'schemaType' => 'Product', 'translations' => []];
        foreach ($site['languages'] as $language) {
            $name = $product['name'][$language] ?? $product['name']['en'];
            $description = $product['description'][$language] ?? $product['description']['en'] ?? '';
            $page['translations'][$language] = [
                'title' => $name,
                'description' => $description,
                'sections' => [['heading' => $language === 'ar' ? 'السعر' : 'Price', 'text' => $product['currency'] . ' ' . number_format((float)$product['price'], 2)]],
                'structuredData' => [
                    '@type' => 'Product',
                    'offers' => ['@type' => 'Offer', 'priceCurrency' => $product['currency'], 'price' => (string)$product['price']],
                ],
            ];
        }
        $pages[] = $page;
    }
    return $pages;
};

<?php
declare(strict_types=1);

return [
    'siteUrl' => 'https://example.com',
    'siteName' => 'Example Studio',
    'template' => 'public/index.html',
    'languages' => ['en', 'ar'],
    'defaultLanguage' => 'en',
    'rootId' => 'app',
    'spaRoutes' => ['/account', '/login', '/checkout'],
    'pages' => [
        ['path' => '/', 'translations' => [
            'en' => ['title' => 'Build something useful', 'nav' => 'Home', 'description' => 'An independent studio building useful websites and tools.', 'sections' => [
                ['heading' => 'What we do', 'text' => ['We design websites, improve existing applications, and help teams ship their work.'], 'items' => ['Website design', 'Application development', 'Ongoing support']],
            ]],
            'ar' => ['title' => 'نبني أدوات مفيدة', 'nav' => 'الرئيسية', 'description' => 'استوديو مستقل لبناء المواقع والأدوات المفيدة.', 'sections' => [
                ['heading' => 'خدماتنا', 'text' => ['نصمم المواقع ونطور التطبيقات ونساعد الفرق على إطلاق مشاريعها.'], 'items' => ['تصميم المواقع', 'تطوير التطبيقات', 'الدعم المستمر']],
            ]],
        ]],
        ['path' => '/about', 'translations' => [
            'en' => ['title' => 'About our studio', 'nav' => 'About', 'description' => 'A small team focused on clear communication and practical results.'],
            'ar' => ['title' => 'عن الاستوديو', 'nav' => 'من نحن', 'description' => 'فريق صغير يهتم بالتواصل الواضح والنتائج العملية.'],
        ]],
    ],
];

<?php
// Пример файла конфигурации
// Скопируйте этот файл в config.php и измените настройки
return [
    'secret_key' => 'YOUR_SECRET_KEY_HERE',

    // Настройки для обхода блокировок
    'proxy' => '', // Пример: 'socks5://user:pass@host:port'
    'cookies_path' => '', // Путь к файлу cookies.txt

    // ===== TikTok (NO WATERMARK) =====
    // Рекомендуется использовать yt-dlp nightly, т.к. TikTok часто меняет API-хосты.
    // Эти параметры используются в [`index.php`](index.php:1) в ветке TikTok.
    'tiktok_api_hostname'   => 'api22-normal-c-useast2a.tiktokv.com',
    'tiktok_user_agent'     => 'Mozilla/5.0 (Linux; Android 12; SM-G991B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Mobile Safari/537.36',
    'tiktok_sleep_requests' => 3,
    'tiktok_sleep_interval' => 5,
    'tiktok_embed_metadata' => true,

    // Дополнительные аргументы для yt-dlp (если нужно переопределить поведение точечно)
    'extra_args' => [
        'tiktok'  => '',
        'instagram' => '',
        'vk' => '',
        'rutube' => '',
    ]
];

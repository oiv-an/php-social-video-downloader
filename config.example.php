<?php
// Пример файла конфигурации
// Скопируйте этот файл в config.php и измените настройки
return [
    'secret_key' => 'YOUR_SECRET_KEY_HERE',
    
    // Настройки для обхода блокировок
    'proxy' => '', // Пример: 'socks5://user:pass@host:port'
    'cookies_path' => '', // Путь к файлу cookies.txt
    
    // Дополнительные аргументы для yt-dlp
    'extra_args' => [
        'tiktok'  => '',
        'instagram' => '',
        'vk' => '',
        'rutube' => '',
    ]
];

<?php

/**
 * Multi-Platform Video Downloader for Ubuntu 22
 * Поддерживает: TikTok, YouTube, Instagram, VK, RuTube
 */

// --- КОНФИГУРАЦИЯ ---
$config = file_exists(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [
    'secret_key' => 'asdajgsodj68fsdaasdf23@$%@!23124',
    'proxy' => '',
    'cookies_path' => '',
    'extra_args' => []
];

$secretKey = $config['secret_key'] ?? 'asdajgsodj68fsdaasdf23@$%@!23124';
$ytDlpPath = 'yt-dlp'; 
// ---------------------

if (file_exists(__DIR__ . '/yt-dlp')) {
    $ytDlpPath = __DIR__ . '/yt-dlp';
}

$tempDir = sys_get_temp_dir();
$filePath = null;

// Гарантированное удаление файла при завершении
register_shutdown_function(function() use (&$filePath) {
    if ($filePath && file_exists($filePath)) {
        @unlink($filePath);
    }
});

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
    exit;
}

// Получение данных
$input = $_POST;
if (empty($input)) {
    $jsonData = json_decode(file_get_contents('php://input'), true);
    $input = $jsonData ?? [];
}

$url = $input['url'] ?? '';
$key = $input['key'] ?? '';

if ($key !== $secretKey) {
    header('Content-Type: application/json');
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden: Invalid key']);
    exit;
}

if (empty($url)) {
    header('Content-Type: application/json');
    http_response_code(400);
    echo json_encode(['error' => 'URL required']);
    exit;
}

// Валидация поддерживаемых платформ
$patterns = [
    'tiktok'    => '/tiktok\.com\//i',
    'instagram' => '/instagram\.com\/(reels?|p|tv)\//i',
    'vk'        => '/vk\.com\/(video|clip)/i',
    'rutube'    => '/rutube\.ru\/video\//i'
];

$platform = null;
foreach ($patterns as $pName => $pattern) {
    if (preg_match($pattern, $url)) {
        $platform = $pName;
        break;
    }
}

if (!$platform) {
    header('Content-Type: application/json');
    http_response_code(400);
    echo json_encode(['error' => 'Unsupported platform or invalid URL']);
    exit;
}

try {
    $downloadUrl = $url;
    $ytDlpArgs = [
        "--no-playlist",
        "--format \"best\"",
        "--no-check-certificate",
        "--no-cache-dir",
        "--socket-timeout 30",
        "--retries 3",
    ];

    // Настройки из конфига
    if (!empty($config['proxy'])) {
        $ytDlpArgs[] = "--proxy " . escapeshellarg($config['proxy']);
    }
    if (!empty($config['cookies_path']) && file_exists($config['cookies_path'])) {
        $ytDlpArgs[] = "--cookies " . escapeshellarg($config['cookies_path']);
    }

    // Специфичная логика для платформ
    switch ($platform) {
        case 'tiktok':
            // Пытаемся извлечь ID для embed ссылки (самый стабильный метод)
            $videoId = null;
            if (preg_match('/video\/(\d+)/', $url, $matches)) {
                $videoId = $matches[1];
            } else {
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_NOBODY, true);
                curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                curl_exec($ch);
                $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
                curl_close($ch);
                if (preg_match('/video\/(\d+)/', $effectiveUrl, $matches)) {
                    $videoId = $matches[1];
                }
            }
            if ($videoId) {
                $downloadUrl = "https://www.tiktok.com/embed/v2/{$videoId}";
            }
            $ytDlpArgs[] = "--user-agent \"Mozilla/5.0 (Linux; Android 12; SM-G991B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Mobile Safari/537.36\"";
            $ytDlpArgs[] = "--extractor-args \"tiktok:api_hostname=api22-normal-c-useast2a.tiktokv.com\"";
            $ytDlpArgs[] = "--sleep-requests 2";
            $ytDlpArgs[] = "--sleep-interval 5";
            break;

        case 'instagram':
            $ytDlpArgs[] = "--user-agent \"Mozilla/5.0 (iPhone; CPU iPhone OS 15_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.0 Mobile/15E148 Safari/604.1\"";
            $ytDlpArgs[] = "--sleep-requests 2";
            $ytDlpArgs[] = "--sleep-interval 2";
            break;

        case 'vk':
        case 'rutube':
            $ytDlpArgs[] = "--user-agent \"Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36\"";
            break;
    }

    // Дополнительные аргументы из конфига (если есть)
    if (!empty($config['extra_args'][$platform])) {
        $ytDlpArgs[] = $config['extra_args'][$platform];
    }

    $fileName = uniqid($platform . '_', true) . '.mp4';
    $filePath = $tempDir . DIRECTORY_SEPARATOR . $fileName;

    $command = escapeshellarg($ytDlpPath) . " " . implode(" ", $ytDlpArgs) . " -o " . escapeshellarg($filePath) . " " . escapeshellarg($downloadUrl) . " 2>&1";

    exec($command, $output, $returnCode);

    if ($returnCode !== 0) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['error' => 'Download failed', 'details' => $output, 'platform' => $platform]);
        exit;
    }

    if (!file_exists($filePath)) {
        header('Content-Type: application/json');
        http_response_code(404);
        echo json_encode(['error' => 'File not found after download']);
        exit;
    }

    // Отправка файла
    header('Content-Type: video/mp4');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . filesize($filePath));
    readfile($filePath);
    exit;

} catch (Exception $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

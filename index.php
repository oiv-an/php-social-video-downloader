<?php

/**
 * Multi-Platform Video Downloader for Ubuntu 22
 * Поддерживает: TikTok, YouTube, Instagram, VK, RuTube
 */

// Fallback для TikTok через сторонний API
function tryTikTokFallback($videoId, $outputPath) {
    $apiUrl = "https://www.tikwm.com/api/?url=https://www.tiktok.com/video/{$videoId}&hd=1";
    
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ],
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || empty($response)) {
        return false;
    }
    
    $data = json_decode($response, true);
    
    // Попробовать разные варианты URL из ответа
    $videoUrl = null;
    if (isset($data['data']['hdplay'])) {
        $videoUrl = $data['data']['hdplay'];
    } elseif (isset($data['data']['play'])) {
        $videoUrl = $data['data']['play'];
    } elseif (isset($data['data']['wmplay'])) {
        $videoUrl = $data['data']['wmplay'];
    }
    
    if (!$videoUrl) {
        return false;
    }
    
    // Скачать видео с правильными headers
    $ch = curl_init($videoUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_BINARYTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_HTTPHEADER => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Referer: https://www.tikwm.com/',
        ],
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    
    $videoData = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || empty($videoData) || strlen($videoData) < 1000) {
        return false;
    }
    
    file_put_contents($outputPath, $videoData);
    return true;
}

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
$fileSuccessfullySent = false;

// Гарантированное удаление файла при завершении (только если НЕ отправлен успешно)
register_shutdown_function(function () use (&$filePath, &$fileSuccessfullySent) {
    if ($filePath && file_exists($filePath) && !$fileSuccessfullySent) {
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
$mode = $input['mode'] ?? 'video'; // video|audio

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

if (!in_array($mode, ['video', 'audio'], true)) {
    header('Content-Type: application/json');
    http_response_code(400);
    echo json_encode(['error' => 'Invalid mode. Use video or audio.']);
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
        "--no-check-certificate",
        "--no-cache-dir",
        "--socket-timeout 30",
        "--retries 3",
    ];

    // Режим: video (по умолчанию) или audio (только аудио)
    if ($mode === 'audio') {
        // Извлекаем аудио и конвертируем в mp3 (требует ffmpeg)
        // Для реального сжатия используем битрейт (по умолчанию 128k).
        $audioBitrate = $input['audio_bitrate'] ?? ($config['audio_bitrate'] ?? '128k');
        if (!preg_match('/^\d{2,3}k$/', (string)$audioBitrate)) {
            $audioBitrate = '128k';
        }

        $ytDlpArgs[] = "--extract-audio";
        $ytDlpArgs[] = "--audio-format mp3";

        // ВАЖНО: --audio-quality в yt-dlp для mp3 не всегда гарантирует CBR.
        // Поэтому принудительно задаём параметры кодека LAME через ffmpeg.
        $ytDlpArgs[] = "--postprocessor-args " . escapeshellarg("ffmpeg:-vn -ac 2 -ar 44100 -codec:a libmp3lame -b:a {$audioBitrate}");

        // Берём только аудио дорожку
        $ytDlpArgs[] = "--format \"bestaudio/best\"";
    } else {
        $ytDlpArgs[] = "--format \"best\"";
    }

    // Настройки из конфига
    if (!empty($config['proxy'])) {
        $ytDlpArgs[] = "--proxy " . escapeshellarg($config['proxy']);
    }
    if (!empty($config['cookies_path']) && file_exists($config['cookies_path'])) {
        $ytDlpArgs[] = "--cookies " . escapeshellarg($config['cookies_path']);
    }
    if (!empty($config['ffmpeg_location'])) {
        // Нужен для postprocessing (например, --embed-metadata)
        $ytDlpArgs[] = "--ffmpeg-location " . escapeshellarg($config['ffmpeg_location']);
    }

    // Специфичная логика для платформ
    switch ($platform) {
        case 'tiktok':
            // ===== TIKTOK SPECIAL HANDLING (WATERMARK-FREE) =====
            // TikTok отдаёт "clean" версию (без водяного знака) мобильным клиентам.
            // Поэтому используем mobile API hostname + Android User-Agent.
            //
            // ВАЖНО: для стабильной работы рекомендуется yt-dlp nightly.

            // Резолвим short-link/редиректы, чтобы yt-dlp применил TikTok extractor (а не generic).
            // Важно: URL вида /embed/v2/... часто уводит yt-dlp в [generic], из-за чего tiktok:* args могут не применяться.
            $resolvedUrl = $url;

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

                if (!empty($effectiveUrl)) {
                    $resolvedUrl = $effectiveUrl;
                }
                if (preg_match('/video\/(\d+)/', $resolvedUrl, $matches)) {
                    $videoId = $matches[1];
                }
            }

            // По умолчанию используем резолвнутый URL (лучше для TikTok extractor)
            $downloadUrl = $resolvedUrl;

            // Опционально: можно включить embed URL через конфиг (не рекомендуется, т.к. может включать generic extractor)
            $tiktokUseEmbedUrl = (bool)($config['tiktok_use_embed_url'] ?? false);
            if ($tiktokUseEmbedUrl && $videoId) {
                $downloadUrl = "https://www.tiktok.com/embed/v2/{$videoId}";
            }

            // Defaults (can be overridden via config.php)
            $tiktokApiHostname = $config['tiktok_api_hostname'] ?? 'api22-normal-c-useast2a.tiktokv.com';
            $tiktokUserAgent = $config['tiktok_user_agent'] ?? 'Mozilla/5.0 (Linux; Android 12; SM-G991B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Mobile Safari/537.36';
            $tiktokSleepRequests = (int)($config['tiktok_sleep_requests'] ?? 3);
            $tiktokSleepInterval = (int)($config['tiktok_sleep_interval'] ?? 5);

            // По умолчанию выключено: требует ffmpeg, иначе yt-dlp завершится с ошибкой postprocessing
            $tiktokEmbedMetadata = (bool)($config['tiktok_embed_metadata'] ?? false);

            // CRITICAL: mobile API hostname (often returns clean video)
            $ytDlpArgs[] = "--extractor-args " . escapeshellarg("tiktok:api_hostname={$tiktokApiHostname}");

            // CRITICAL: Android User-Agent (TikTok treats as mobile client)
            $ytDlpArgs[] = "--user-agent " . escapeshellarg($tiktokUserAgent);

            // Rate limiting to avoid blocks
            if ($tiktokSleepRequests > 0) {
                $ytDlpArgs[] = "--sleep-requests " . $tiktokSleepRequests;
            }
            if ($tiktokSleepInterval > 0) {
                $ytDlpArgs[] = "--sleep-interval " . $tiktokSleepInterval;
            }

            // Optional: embed metadata
            if ($tiktokEmbedMetadata) {
                $ytDlpArgs[] = "--embed-metadata";
            }
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

    $ext = ($mode === 'audio') ? 'mp3' : 'mp4';
    $fileName = uniqid($platform . '_', true) . '.' . $ext;
    $filePath = $tempDir . DIRECTORY_SEPARATOR . $fileName;

    // Оставляем как было (фиксированное имя .mp3/.mp4), чтобы не ломать поведение.
    // Сжатие обеспечивается параметрами --audio-quality/--postprocessor-args выше.
    $command = escapeshellarg($ytDlpPath) . " " . implode(" ", $ytDlpArgs) . " -o " . escapeshellarg($filePath) . " " . escapeshellarg($downloadUrl) . " 2>&1";

    // Явно устанавливаем переменные окружения для exec() чтобы yt-dlp работал как от root
    $originalEnv = [];
    $env = [
        'PATH' => getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        'HOME' => sys_get_temp_dir(), // Временная HOME для www user
    ];
    
    foreach ($env as $key => $value) {
        if (!empty($value)) {
            $originalEnv[$key] = getenv($key);
            putenv("$key=$value");
        }
    }
    
    exec($command, $output, $returnCode);
    
    // Восстанавливаем оригинальные переменные окружения
    foreach ($originalEnv as $key => $value) {
        if ($value === false) {
            putenv($key); // Удаляем если не было
        } else {
            putenv("$key=$value"); // Восстанавливаем
        }
    }

    if ($returnCode !== 0) {
        // Частный случай: видео скачано, но postprocessing упал (например, --embed-metadata без ffmpeg).
        $outputText = implode("\n", $output);
        $looksLikeFfmpegMissing = (stripos($outputText, 'ffmpeg not found') !== false);

        if ($looksLikeFfmpegMissing && $filePath && file_exists($filePath) && filesize($filePath) > 0) {
            // Продолжаем и отдаём скачанный файл без метаданных
        } else {
            // TikTok fallback: попробовать альтернативный метод через API
            if ($platform === 'tiktok' && !empty($videoId)) {
                $fallbackOk = tryTikTokFallback($videoId, $filePath);

                // ВАЖНО: fallback скачивает ВИДЕО (mp4). Если запрошен audio — нужно перекодировать в mp3.
                if ($fallbackOk && file_exists($filePath) && filesize($filePath) > 0) {
                    if ($mode === 'audio') {
                        $audioBitrate = $input['audio_bitrate'] ?? ($config['audio_bitrate'] ?? '128k');
                        if (!preg_match('/^\d{2,3}k$/', (string)$audioBitrate)) {
                            $audioBitrate = '128k';
                        }

                        $tmpMp3 = $tempDir . DIRECTORY_SEPARATOR . uniqid('audio_', true) . '.mp3';
                        $ffmpegCmd = "ffmpeg -y -hide_banner -loglevel error -i " . escapeshellarg($filePath) .
                            " -vn -ac 2 -ar 44100 -codec:a libmp3lame -b:a {$audioBitrate} " . escapeshellarg($tmpMp3) . " 2>&1";
                        $ffOut = [];
                        $ffCode = 0;
                        @exec($ffmpegCmd, $ffOut, $ffCode);

                        if ($ffCode === 0 && file_exists($tmpMp3) && filesize($tmpMp3) > 0) {
                            @unlink($filePath);
                            $filePath = $tmpMp3;
                            $fileName = preg_replace('/\.mp4$/i', '.mp3', $fileName);
                        } else {
                            header('Content-Type: application/json');
                            http_response_code(500);
                            echo json_encode([
                                'error' => 'Download failed (fallback video ok, but mp3 transcode failed)',
                                'details' => $output,
                                'platform' => $platform,
                                'debug_command' => $command,
                                'ffmpeg_cmd' => $ffmpegCmd,
                                'ffmpeg_output' => $ffOut,
                                'ffmpeg_return_code' => $ffCode,
                            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            exit;
                        }
                    }
                    // Fallback сработал, продолжаем
                } else {
                    header('Content-Type: application/json');
                    http_response_code(500);
                    echo json_encode([
                        'error' => 'Download failed (tried fallback)',
                        'details' => $output,
                        'platform' => $platform,
                        'debug_command' => $command
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    exit;
                }
            } else {
                header('Content-Type: application/json');
                http_response_code(500);
                echo json_encode([
                    'error' => 'Download failed',
                    'details' => $output,
                    'platform' => $platform,
                    'debug_command' => $command
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
        }
    }

    if (!file_exists($filePath)) {
        header('Content-Type: application/json');
        http_response_code(404);
        echo json_encode(['error' => 'File not found after download']);
        exit;
    }

    // ВРЕМЕННО: вместо отдачи файла возвращаем JSON с подробностями (только если debug=1)
    $debug = (string)($input['debug'] ?? '') === '1';
    if ($mode === 'audio' && $debug) {
        $size = @filesize($filePath);
        $mime = null;
        if (function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = @finfo_file($finfo, $filePath) ?: null;
                @finfo_close($finfo);
            }
        }

        $headHex = null;
        $headAscii = null;
        $fp = @fopen($filePath, 'rb');
        if ($fp) {
            $head = @fread($fp, 64);
            @fclose($fp);
            if ($head !== false) {
                $headHex = bin2hex($head);
                $headAscii = preg_replace('/[^\x20-\x7E]/', '.', $head);
            }
        }

        // Попробуем получить реальный битрейт/кодек через ffprobe (если доступен)
        $ffprobeInfo = null;
        $ffprobeCmd = "ffprobe -v error -select_streams a:0 -show_entries stream=codec_name,codec_type,bit_rate,sample_rate,channels,duration -of json " . escapeshellarg($filePath) . " 2>&1";
        $ffprobeOut = [];
        $ffprobeCode = 0;
        @exec($ffprobeCmd, $ffprobeOut, $ffprobeCode);
        if ($ffprobeCode === 0 && !empty($ffprobeOut)) {
            $ffprobeInfo = json_decode(implode("\n", $ffprobeOut), true);
        }

        // Очищаем все буферы вывода, чтобы не ломать JSON
        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: application/json; charset=utf-8');

        $payload = [
            'mode' => $mode,
            'platform' => $platform,
            'download_url' => $downloadUrl,
            'file_name' => $fileName,
            'file_path' => $filePath,
            'file_exists' => true,
            'file_size_bytes' => $size,
            'file_size_mb' => $size ? round($size / 1024 / 1024, 2) : null,
            'mime' => $mime,
            'head_hex_64' => $headHex,
            'head_ascii_64' => $headAscii,
            'yt_dlp_command' => $command,
            'yt_dlp_return_code' => $returnCode,
            'yt_dlp_output' => $output,
            'ffprobe_cmd' => $ffprobeCmd,
            'ffprobe_return_code' => $ffprobeCode,
            'ffprobe_raw' => $ffprobeOut,
            'ffprobe_json' => $ffprobeInfo,
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            // Фоллбек: если в выводе есть невалидные байты, чистим их и кодируем снова
            $payload['json_encode_error'] = json_last_error_msg();
            $payload['yt_dlp_output'] = array_map(static function ($line) {
                return is_string($line) ? mb_convert_encoding($line, 'UTF-8', 'UTF-8') : $line;
            }, $payload['yt_dlp_output'] ?? []);
            $payload['ffprobe_raw'] = array_map(static function ($line) {
                return is_string($line) ? mb_convert_encoding($line, 'UTF-8', 'UTF-8') : $line;
            }, $payload['ffprobe_raw'] ?? []);
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        echo $json !== false ? $json : '{"error":"Failed to encode JSON","details":"' . addslashes(json_last_error_msg()) . '"}';

        // Удаляем файл после диагностики
        @unlink($filePath);
        exit;
    }

    // Отправка файла
    $fileSuccessfullySent = true; // Отключаем автоудаление

    // Очищаем все буферы вывода
    while (ob_get_level()) {
        ob_end_clean();
    }

    if ($mode === 'audio') {
        header('Content-Type: audio/mpeg');
    } else {
        header('Content-Type: video/mp4');
    }

    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: public');
    header('Expires: 0');

    // Отправляем файл по частям для надежности
    $fp = fopen($filePath, 'rb');
    if ($fp) {
        while (!feof($fp)) {
            echo fread($fp, 8192);
            flush();
        }
        fclose($fp);
    }

    // Удаляем файл после успешной отправки
    @unlink($filePath);
    exit;
} catch (Exception $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

<?php
declare(strict_types=1);

// Configuración: copia config.example.php a config.php y rellena el token.
const BASE_URL    = 'https://sige.gva.es/qsige.localizador/citaPrevia/disponible';
const BOOKING_URL = 'https://sige.gva.es/qsige/citaprevia.justicia/#/es/home?uuid=01E4-33B69-2883-5B9B8';

function httpRequest(string $url, array $post = []): ?string
{
    $curl = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 15,
    ];
    if ($post) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = http_build_query($post);
    }
    curl_setopt_array($curl, $options);

    $response = curl_exec($curl);
    $status   = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error    = curl_error($curl);
    curl_close($curl);

    if ($response === false || $status >= 400) {
        error_log("HTTP error ({$status}) en {$url}: {$error}");
        return null;
    }
    return $response;
}

function getJson(string $url): ?object
{
    $body = httpRequest($url);
    if ($body === null) {
        return null;
    }
    $data = json_decode($body);
    return is_object($data) || is_array($data) ? (object) $data : null;
}

function sendTelegram(string $text): void
{
    $config  = @include __DIR__ . '/config.php';
    $token   = getenv('TELEGRAM_BOT_TOKEN') ?: ($config['telegram_token'] ?? null);
    $channel = getenv('TELEGRAM_CHANNEL') ?: ($config['telegram_channel'] ?? '@alertas_citas');
    if (!$token) {
        error_log('Falta telegram_token en config.php');
        return;
    }
    httpRequest("https://api.telegram.org/bot{$token}/sendMessage", [
        'chat_id'    => $channel,
        'text'       => $text,
        'parse_mode' => 'HTML',
    ]);
}

function getCalendarDays(int $centerId, int $serviceId): ?array
{
    $calendar = getJson(BASE_URL . "/centro/{$centerId}/servicio/{$serviceId}/calendario");
    if ($calendar === null || empty($calendar->dias)) {
        return null;
    }
    return $calendar->dias;
}

function hasHours(int $centerId, int $serviceId, string $day): bool
{
    $date = (new DateTime($day))->format('mdY');
    $url  = BASE_URL . "/horas/centro/{$centerId}/servicio/{$serviceId}/fecha/{$date}";
    $hours = getJson($url);
    return $hours !== null && !empty((array) $hours);
}

function findAvailableDays(array $days, int $centerId, int $serviceId): array
{
    $available = [];
    foreach ($days as $day) {
        try {
            if (hasHours($centerId, $serviceId, $day->dia)) {
                $available[] = $day->dia;
            }
        } catch (Exception $e) {
            error_log("Fecha inválida: {$day->dia}");
        }
    }
    return $available;
}

// --- Main ---
if (PHP_SAPI === 'cli') {
    // GitHub Actions / terminal: SERVICE_ID, CENTER_ID y TEST=1 por variables de entorno
    $serviceId = filter_var(getenv('SERVICE_ID'), FILTER_VALIDATE_INT);
    $centerId  = filter_var(getenv('CENTER_ID'), FILTER_VALIDATE_INT);
    $test      = getenv('TEST') === '1';
} else {
    $serviceId = filter_input(INPUT_GET, 'serviceId', FILTER_VALIDATE_INT);
    $centerId  = filter_input(INPUT_GET, 'centerId', FILTER_VALIDATE_INT);
    $test      = isset($_GET['test']);
}

if (!$serviceId || !$centerId) {
    http_response_code(400);
    exit('Parámetros serviceId y centerId obligatorios (enteros).');
}

if ($test) {
    header('Content-Type: text/plain; charset=utf-8');
    $cfg = @include __DIR__ . '/config.php';
    echo 'config.php: ' . (is_array($cfg) ? 'OK' : 'NO se encuentra o no devuelve un array') . "\n";
    echo 'token: ' . (!empty($cfg['telegram_token']) ? 'definido' : 'VACIO') . "\n";
    echo 'canal: ' . ($cfg['telegram_channel'] ?? '(por defecto)') . "\n";
    echo 'curl: ' . (function_exists('curl_init') ? 'OK' : 'NO disponible') . "\n";
    $r = httpRequest('https://api.telegram.org/bot' . (getenv('TELEGRAM_BOT_TOKEN') ?: ($cfg['telegram_token'] ?? '')) . '/getMe');
    echo 'Telegram getMe: ' . ($r === null ? 'FALLA (bloqueo del hosting, token incorrecto o sin red)' : $r) . "\n";
    $r = httpRequest(BASE_URL . "/centro/{$centerId}/servicio/{$serviceId}/calendario");
    echo 'SIGE calendario: ' . ($r === null ? 'FALLA' : substr($r, 0, 150)) . "\n---\n";
}

$days = getCalendarDays($centerId, $serviceId);
if ($days === null) {
    $message = '<strong>No hay citas</strong> (calendario vacío o error al consultarlo)';
    if ($test) {
        sendTelegram($message);
    }
    exit($message);
}

$available = findAvailableDays($days, $centerId, $serviceId);

if ($available) {
    $list = htmlspecialchars(implode(', ', $available));
    $message = "<strong>¡Hay citas!</strong> Días: {$list}\n<a href='" . BOOKING_URL . "'>ACCEDER</a>";
    sendTelegram($message);
} else {
    $message = '<strong>No hay citas</strong>';
    if ($test) {
        sendTelegram($message);
    }
}
echo strip_tags($message);

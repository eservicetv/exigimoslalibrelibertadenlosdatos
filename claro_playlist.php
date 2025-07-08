<?php
declare(strict_types=1);

/**
 * ClaroTV – Entrega dinámica de playlists HLS con caché local
 * Optimizado
 * jul-2025
 */

date_default_timezone_set('America/Argentina/Buenos_Aires');

/** Configuración principal */
const CACHE_DIR = '/tmp/claro_cache/';
const CACHE_MARGIN = 60; // segundos de margen antes de expirar

$headers = [
    'User-Agent: AndroidDlaApk AndroidDlaApkAccedo',
];
$proxy = 'http://127.0.0.1:4000'; // Modificar o vaciar si no se usa proxy

$clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'];
$groupId  = $_GET['group_id'] ?? '';
if ($groupId === '') {
    http_response_code(400);
    exit('Falta group_id');
}

ensure_cache_dir();

$cacheKey  = md5('m3u8_' . $groupId);
$cacheM3u8 = CACHE_DIR . "{$cacheKey}.m3u8";
$cacheMeta = CACHE_DIR . "{$cacheKey}.json";

if (is_cache_valid($cacheM3u8, $cacheMeta)) {
    deliver_m3u8($cacheM3u8, $clientIp, true);
    exit;
}

@unlink($cacheM3u8);
@unlink($cacheMeta);

/** Obtención o renovación de tokens */
$usertoken = get_token_cached(
    'https://mfwktabletandroid-api.clarovideo.net/services/user/v1/isloggedin?device_type=ASUS_Z01QD&device_id=10639432&device_category=tablet&device_manufacturer=Asus&device_so=Android%207.1.2&region=argentina&authpt=12e4i8l6a581a&device_model=android&authpn=amco&HKS=106394326613427451510&user_id=55750161&includpaywayprofile=1',
    '/tmp/usertoken.jwt',
    fn($j) => $j['response']['user_token'] ?? ''
);
$paywaytoken = get_token_cached(
    'https://mfwktabletandroid-api.clarovideo.net/services/payway/linealchannels?device_type=ASUS_Z01QD&api_version=v5.93&device_category=tablet&device_manufacturer=Asus&region=argentina&authpt=12e4i8l6a581a&device_model=android&authpn=amco&HKS=106394326613427451510&user_id=55750161',
    '/tmp/paywaytoken.jwt',
    fn($j) => $j['response']['paqs']['paq'][0]['payway_token'] ?? ''
);

if (!$usertoken || !$paywaytoken) {
    http_response_code(500);
    exit('Error obteniendo tokens');
}

$postData = http_build_query([
    'user_token'   => $usertoken,
    'payway_token' => $paywaytoken,
]);

$getmediaUrl = "https://mfwkmobileandroid-api.clarovideo.net/services/player/getmedia?" .
    "preview=0&authpt=12e4i8l6a581a&css=0&device_model=android" .
    "&device_id=fe98e51c-20d1-451e-9c4a-4b3ff05fd385" .
    "&device_so=Android%2012&format=json&device_type=SM-A725M" .
    "&authpn=amco&api_version=v5.93&device_category=mobile" .
    "&device_manufacturer=samsung&HKS=fe98e51c20d1451e9c4a4b3ff05fd38563f567876362f" .
    "&device_name=a72qub&user_id=55750161" .
    "&user_hash=NTU3NTAxNjF8MTY3NzA1NDA2NHwzOTE2ZmQ5OGM3Y2Q5ZTAxMDIwZDcxNzdkMjkxNjZlYjRjMjQwZGNhNDU4MGU5N2MxYw%3D%3D" .
    "&group_id=" . urlencode($groupId) .
    "&stream_type=hls_kr&region=argentina";

$response = http_request($getmediaUrl, $postData, $headers, $proxy);
$data      = json_decode($response, true);
$videoUrl  = $data['response']['media']['video_url'] ?? null;
if (!$videoUrl || !preg_match('/[?&]exp=(\d+)/', $videoUrl, $m)) {
    http_response_code(500);
    exit('No se encontró video_url o exp');
}
$exp = (int)$m[1];

$m3u8 = http_request($videoUrl, null, $headers, $proxy);
file_put_contents($cacheM3u8, $m3u8);
file_put_contents($cacheMeta, json_encode(['exp' => $exp, 'video_url' => $videoUrl]));

deliver_m3u8($cacheM3u8, $clientIp, false);

/** Funciones auxiliares */
function ensure_cache_dir(): void
{
    if (!is_dir(CACHE_DIR)) {
        mkdir(CACHE_DIR, 0755, true);
    }
}

function is_cache_valid(string $path, string $metaPath): bool
{
    if (!file_exists($path) || !file_exists($metaPath)) {
        return false;
    }
    $meta = json_decode(file_get_contents($metaPath), true);
    $exp  = $meta['exp'] ?? 0;
    return ($exp - time()) > CACHE_MARGIN;
}

function deliver_m3u8(string $path, string $ip, bool $cacheHit): void
{
    header('X-Cache: ' . ($cacheHit ? 'HIT' : 'MISS'));
    header('Content-Type: application/vnd.apple.mpegurl');
    $content = file_get_contents($path);
    $content = preg_replace('/(?<=\?|&)(ip=)([0-9\.]+)(?=&|$)/', '$1' . $ip, $content);
    echo $content;
}

function get_jwt_exp(string $jwt): int
{
    $parts = explode('.', $jwt);
    if (count($parts) < 2) {
        return time() + 60;
    }
    $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
    return $payload['exp'] ?? (time() + 60);
}

function get_token_cached(string $url, string $cacheFile, callable $extract)
{
    if (file_exists($cacheFile)) {
        $data = json_decode(file_get_contents($cacheFile), true);
        if (time() < ($data['exp'] ?? 0)) {
            return $data['token'];
        }
    }
    $res = http_request($url, null, [], null);
    if ($res === null) {
        return null;
    }
    $json  = json_decode($res, true);
    $token = $extract($json) ?? '';
    $exp   = get_jwt_exp($token);
    file_put_contents($cacheFile, json_encode(['token' => $token, 'exp' => $exp]));
    return $token;
}

function http_request(string $url, ?string $postData, array $headers, ?string $proxy): ?string
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    if ($postData !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    }
    if ($proxy) {
        curl_setopt($ch, CURLOPT_PROXY, $proxy);
    }
    if ($headers) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    $data = curl_exec($ch);
    curl_close($ch);
    return $data !== false ? $data : null;
}


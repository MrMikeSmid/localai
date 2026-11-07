<?php
/**
 * Antwoorden op de server laten doorlopen, ook als de pagina wordt gesloten.
 *
 * POST {action:'start', body:{...chat/completions...}} -> {ok, id}
 *      De server vraagt het antwoord aan het model (Base URL uit de gedeelde instellingen,
 *      nooit uit het verzoek) en schrijft de tekst telkens weg in storage/jobs/<id>.php.
 * GET  ?id=<id>                                         -> {ok, status, content, reasoning, ...}
 * POST {action:'cancel', id}                            -> {ok}
 *
 * Vereist: gedeelde instellingen (chat-config.php), php-curl, en een Base URL die vanaf
 * deze webserver bereikbaar is (dus geen 192.168.x.x thuisadres).
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const GUARD = "<?php http_response_code(404); exit; ?>\n";
const MAX_BODY = 30 * 1024 * 1024;   // foto's en video-beelden
const MAX_RUNNING_PER_IP = 2;
const MAX_STARTS_PER_10MIN = 30;
const JOB_TTL = 86400;

$dir = __DIR__ . '/storage';
$jobs = $dir . '/jobs';

function out(array $d, int $code = 200): never {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function cfg(string $dir): ?array {
    $f = $dir . '/chat-config.php';
    if (!is_file($f)) return null;
    $raw = @file_get_contents($f);
    if ($raw === false || strncmp($raw, GUARD, strlen(GUARD)) !== 0) return null;
    $j = json_decode(substr($raw, strlen(GUARD)), true);
    return is_array($j) && isset($j['config']) ? $j : null;
}

function jobFile(string $jobs, string $id): string { return $jobs . '/' . $id . '.php'; }

function writeJob(string $jobs, string $id, array $st): void {
    $f = jobFile($jobs, $id);
    $tmp = $f . '.' . bin2hex(random_bytes(3)) . '.tmp';
    if (@file_put_contents($tmp, GUARD . json_encode($st, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) !== false) @rename($tmp, $f);
}

function readJob(string $jobs, string $id): ?array {
    $raw = @file_get_contents(jobFile($jobs, $id));
    $j = ($raw === false || strncmp($raw, GUARD, strlen(GUARD)) !== 0) ? null : json_decode(substr($raw, strlen(GUARD)), true);
    return is_array($j) ? $j : null;
}

function validId(string $id): bool { return (bool)preg_match('/^[a-f0-9]{24}$/', $id); }

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = (string)($_GET['id'] ?? '');
    if (!validId($id)) out(['ok' => false, 'error' => 'Ongeldige taak.'], 400);
    $j = readJob($jobs, $id);
    if (!$j) out(['ok' => false, 'error' => 'Deze taak bestaat niet meer.'], 404);
    // een werker die al lang niets meer schreef, is gestopt (bijv. door een tijdslimiet van de host)
    if ($j['status'] === 'running' && time() - (int)$j['updated'] > 120) {
        $j['status'] = 'error';
        $j['error'] = 'De server stopte met dit antwoord (tijdslimiet van de webhost?).';
    }
    unset($j['ip']);
    out(['ok' => true] + $j);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['ok' => false, 'error' => 'Methode niet toegestaan.'], 405);

$raw = file_get_contents('php://input', false, null, 0, MAX_BODY + 1);
if ($raw === false || strlen($raw) > MAX_BODY) out(['ok' => false, 'error' => 'Bericht te groot.'], 413);
$in = json_decode($raw, true);
if (!is_array($in)) out(['ok' => false, 'error' => 'Ongeldig verzoek.'], 400);
$action = $in['action'] ?? '';

if ($action === 'cancel') {
    $id = (string)($in['id'] ?? '');
    if (!validId($id)) out(['ok' => false, 'error' => 'Ongeldige taak.'], 400);
    if (is_dir($jobs)) @touch($jobs . '/' . $id . '.cancel');
    out(['ok' => true]);
}
if ($action !== 'start') out(['ok' => false, 'error' => 'Onbekende actie.'], 400);

$c = cfg($dir);
$conf = $c['config'] ?? null;
$baseUrl = is_array($conf) ? rtrim((string)($conf['baseUrl'] ?? ''), '/') : '';
if ($baseUrl === '' || !preg_match('#^https?://#i', $baseUrl)) out(['ok' => false, 'error' => 'Geen gedeelde verbinding ingesteld.', 'fallback' => true], 400);
if (!function_exists('curl_init')) out(['ok' => false, 'error' => 'PHP-curl ontbreekt op deze server.', 'fallback' => true], 501);

$body = $in['body'] ?? null;
if (!is_array($body) || !isset($body['messages']) || !is_array($body['messages']) || !$body['messages'] || count($body['messages']) > 400) out(['ok' => false, 'error' => 'Ongeldig bericht.'], 400);
if (!empty($conf['model'])) $body['model'] = (string)$conf['model'];   // alleen het gekozen model
$body['stream'] = true;
$body['stream_options'] = ['include_usage' => true];

if (!is_dir($jobs) && !@mkdir($jobs, 0755, true) && !is_dir($jobs)) out(['ok' => false, 'error' => 'Kan opslagmap niet aanmaken.'], 500);
if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");

// opruimen en beperken per IP
$ip = sha1($_SERVER['REMOTE_ADDR'] ?? '');
$now = time();
$running = 0; $recent = 0;
foreach (glob($jobs . '/*.{php,cancel}', GLOB_BRACE) ?: [] as $f) {
    $mt = @filemtime($f);
    if ($mt !== false && $mt < $now - JOB_TTL) { @unlink($f); continue; }
    if (substr($f, -4) !== '.php') continue;
    $j = readJob($jobs, basename($f, '.php'));
    if (!is_array($j) || ($j['ip'] ?? '') !== $ip) continue;
    if ($mt >= $now - 600) $recent++;
    if (($j['status'] ?? '') === 'running' && $now - (int)($j['updated'] ?? 0) <= 120) $running++;
}
if ($running >= MAX_RUNNING_PER_IP) out(['ok' => false, 'error' => 'Er loopt al een antwoord. Wacht tot dat klaar is.'], 429);
if ($recent >= MAX_STARTS_PER_10MIN) out(['ok' => false, 'error' => 'Te veel verzoeken. Probeer het over een paar minuten opnieuw.'], 429);

$id = bin2hex(random_bytes(12));
$st = ['unreachable' => false, 'status' => 'running', 'content' => '', 'reasoning' => '', 'finish' => null, 'usage' => null, 'error' => null,
       'started' => microtime(true), 'first' => null, 'ended' => null, 'updated' => $now, 'ip' => $ip];
writeJob($jobs, $id, $st);

// Antwoord meteen terugsturen en de verbinding met de browser sluiten; daarna doorwerken.
ignore_user_abort(true);
@set_time_limit(0);
$resp = json_encode(['ok' => true, 'id' => $id]);
header('Connection: close');
header('Content-Length: ' . strlen($resp));
echo $resp;
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    while (ob_get_level() > 0) @ob_end_flush();
    @flush();
}

$headers = ['Content-Type: application/json', 'Accept: text/event-stream'];
$key = trim((string)($c['apiKey'] ?? ''));
if ($key !== '') $headers[] = 'Authorization: Bearer ' . $key;

$buf = ''; $errRaw = ''; $lastWrite = 0.0; $cancelFile = $jobs . '/' . $id . '.cancel';
$flushJob = function (bool $force = false) use (&$st, $jobs, $id, &$lastWrite) {
    $t = microtime(true);
    if (!$force && $t - $lastWrite < 0.25) return;
    $st['updated'] = time();
    writeJob($jobs, $id, $st);
    $lastWrite = $t;
};

$ch = curl_init($baseUrl . '/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_LOW_SPEED_LIMIT => 1,
    CURLOPT_LOW_SPEED_TIME => 600,
    CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$buf, &$errRaw, &$st, $flushJob, $cancelFile) {
        if (is_file($cancelFile)) return 0;
        if ((int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE) >= 400) { if (strlen($errRaw) < 20000) $errRaw .= $chunk; return strlen($chunk); }
        $buf .= $chunk;
        while (($nl = strpos($buf, "\n")) !== false) {
            $line = trim(substr($buf, 0, $nl));
            $buf = substr($buf, $nl + 1);
            if (strncmp($line, 'data:', 5) !== 0) continue;
            $d = trim(substr($line, 5));
            if ($d === '' || $d === '[DONE]') continue;
            $j = json_decode($d, true);
            if (!is_array($j)) continue;
            if (isset($j['error'])) { $st['error'] = is_array($j['error']) ? (string)($j['error']['message'] ?? json_encode($j['error'])) : (string)$j['error']; continue; }
            $choice = $j['choices'][0] ?? null;
            if (is_array($choice)) {
                $dl = $choice['delta'] ?? [];
                $txt = (string)($dl['content'] ?? '');
                $rs = (string)($dl['reasoning_content'] ?? $dl['reasoning'] ?? '');
                if ($txt !== '' || $rs !== '') {
                    if ($st['first'] === null) $st['first'] = microtime(true);
                    $st['content'] .= $txt; $st['reasoning'] .= $rs;
                }
                if (!empty($choice['finish_reason'])) $st['finish'] = $choice['finish_reason'];
            }
            if (isset($j['usage']) && is_array($j['usage'])) $st['usage'] = $j['usage'];
        }
        $flushJob();
        return strlen($chunk);
    },
]);
$ok = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$cerr = curl_error($ch);
curl_close($ch);

$cancelled = is_file($cancelFile);
if ($cancelled) {
    @unlink($cancelFile);
    $st['status'] = 'done';
} elseif ($code >= 400) {
    $m = $errRaw; $j = json_decode($errRaw, true);
    if (is_array($j)) $m = is_array($j['error'] ?? null) ? (string)($j['error']['message'] ?? $errRaw) : (string)($j['error'] ?? $errRaw);
    $st['status'] = 'error'; $st['error'] = "HTTP $code: " . mb_substr($m, 0, 500);
} elseif ($ok === false && $st['content'] === '' && $st['reasoning'] === '') {
    $st['status'] = 'error'; $st['unreachable'] = true; $st['error'] = 'Kan het model niet bereiken vanaf de server (' . $cerr . '). Is de Base URL publiek bereikbaar?';
} else {
    $st['status'] = $st['error'] ? 'error' : 'done';
}
$st['ended'] = microtime(true);
$flushJob(true);

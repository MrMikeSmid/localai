<?php
/**
 * Antwoorden op de server laten doorlopen, ook als de pagina wordt gesloten.
 *
 * POST {action:'test'}                                  -> {ok, result:{ok,status,models,model_found,error}}
POST {action:'start', body:{...chat/completions...}} -> {ok, id}
 *      De server vraagt het antwoord aan het model (Base URL uit de gedeelde instellingen,
 *      nooit uit het verzoek) en schrijft de tekst telkens weg in storage/jobs/<id>.php.
 * GET  ?id=<id>                                         -> {ok, status, content, reasoning, ...}
 * POST {action:'cancel', id}                            -> {ok}
 *
 * Vereist: gedeelde instellingen (chat-config.php), php-curl, en een Base URL die vanaf
 * deze webserver bereikbaar is (dus geen 192.168.x.x thuisadres).
 *
 * De AI-tools (datum/tijd, zoeken, pagina's lezen, geheugen, database, status) staan in ai/ en worden
 * hier door ai/Controller.php uitgevoerd. Zie ai/README.md.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require __DIR__ . '/ai/bootstrap.php';

use Rv\Ai\Config;
use Rv\Ai\Controller;
use Rv\Ai\Db;
use Rv\Ai\Gateway;
use Rv\Ai\Log;
use Rv\Ai\SafeFetch;

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

// identiteit: een door de browser bewaard geheim token (er is geen accountsysteem); het model bepaalt dit nooit
$cid = is_string($in['client_id'] ?? null) && preg_match('/^[a-f0-9]{32}$/', $in['client_id']) ? $in['client_id'] : null;
$userId = $cid !== null ? substr(hash('sha256', 'rv-ai|' . $cid), 0, 32) : null;
$convId = is_string($in['conversation_id'] ?? null) && Db::validId($in['conversation_id']) ? $in['conversation_id'] : null;

$c = cfg($dir);
$conf = $c['config'] ?? null;
// Verbinding: omgevingsvariabelen (AI_BASE_URL, AI_MODEL, AI_API_KEY) gaan voor op de gedeelde instellingen. De sleutel blijft op de server.
$cfgObj = new Config($dir, array_merge(is_array($conf) ? $conf : [], ['apiKey' => (string)($c['apiKey'] ?? '')]));
// beheerder? (alleen daarmee mogen niet-opgeslagen waarden worden getest en krijgt de status meer details)
$isAdmin = is_array($c) && isset($c['hash']) && is_string($in['password'] ?? null) && $in['password'] !== '' && password_verify($in['password'], $c['hash']);

if ($action === 'test') {
    // Verbindingstest vanaf de server (GET /v1/models met Bearer): geen CORS en de sleutel komt nooit in de browser.
    $tip = sha1($_SERVER['REMOTE_ADDR'] ?? '');
    try {
        if (extension_loaded('pdo_sqlite') && !(new Db($dir . '/ai.sqlite'))->rateHit('conntest:' . $tip, 15, 60)) out(['ok' => false, 'error' => 'Te veel tests. Probeer het over een minuut opnieuw.'], 429);
    } catch (Throwable $e) { /* zonder rate limit doorgaan */ }
    if (!function_exists('curl_init')) out(['ok' => false, 'error' => 'PHP-curl ontbreekt op deze server.', 'fallback' => true], 501);
    $tBase = (string)$cfgObj->get('LM_STUDIO_BASE_URL', '');
    $tKey = (string)$cfgObj->get('LM_STUDIO_API_KEY', '');
    $tModel = (string)$cfgObj->get('LM_STUDIO_MODEL_ID', '');
    if ($isAdmin) {   // de beheerder test de ingevulde, nog niet opgeslagen waarden
        if (is_string($in['base_url'] ?? null) && trim($in['base_url']) !== '') $tBase = trim($in['base_url']);
        if (is_string($in['api_key'] ?? null) && trim($in['api_key']) !== '') $tKey = trim($in['api_key']);
        if (is_string($in['model'] ?? null)) $tModel = trim($in['model']);
    }
    if (trim($tBase) === '') out(['ok' => false, 'error' => 'Op de server is nog geen verbinding ingesteld.', 'fallback' => true], 400);
    out(['ok' => true, 'result' => Gateway::test($tBase, $tKey, $tModel, $cfgObj->int('AI_WEB_TIMEOUT_SECONDS', 8, 2, 30))]);
}

if ($action === 'forget' || $action === 'delete_conversation') {
    if ($userId === null) out(['ok' => false, 'error' => 'Geen identiteit.'], 400);
    if ($action === 'delete_conversation' && $convId === null) out(['ok' => false, 'error' => 'Ongeldig gesprek.'], 400);
    try {
        $db = is_file($dir . '/ai.sqlite') ? new Db($dir . '/ai.sqlite') : null;
        if ($db) { $action === 'forget' ? $db->forget($userId) : $db->deleteConversation($userId, $convId); }
    } catch (Throwable $e) { out(['ok' => false, 'error' => 'Verwijderen mislukt.'], 500); }
    out(['ok' => true]);
}
if ($action !== 'start') out(['ok' => false, 'error' => 'Onbekende actie.'], 400);

$baseUrl = rtrim((string)$cfgObj->get('LM_STUDIO_BASE_URL', ''), '/');
if ($baseUrl === '' || !preg_match('#^https?://#i', $baseUrl)) out(['ok' => false, 'error' => 'Geen gedeelde verbinding ingesteld.', 'fallback' => true], 400);
if (!function_exists('curl_init')) out(['ok' => false, 'error' => 'PHP-curl ontbreekt op deze server.', 'fallback' => true], 501);

$body = $in['body'] ?? null;
if (!is_array($body) || !isset($body['messages']) || !is_array($body['messages']) || !$body['messages'] || count($body['messages']) > 400) out(['ok' => false, 'error' => 'Ongeldig bericht.'], 400);
if ($cfgObj->get('LM_STUDIO_MODEL_ID')) $body['model'] = (string)$cfgObj->get('LM_STUDIO_MODEL_ID');   // alleen het ingestelde model
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
$st = ['tools' => [], 'sources' => [], 'note' => null, 'unreachable' => false, 'status' => 'running', 'content' => '', 'reasoning' => '', 'finish' => null, 'usage' => null, 'error' => null,
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

$flushJob = function (bool $force = false) use (&$st, $jobs, $id, &$lastWrite) {
    $t = microtime(true);
    if (!$force && $t - $lastWrite < 0.25) return;
    $st['updated'] = time();
    writeJob($jobs, $id, $st);
    $lastWrite = $t;
};
$lastWrite = 0.0;
$cancelFile = $jobs . '/' . $id . '.cancel';

$db = null;
try {
    if (extension_loaded('pdo_sqlite')) {
        $db = new Db($cfgObj->get('AI_DB_PATH') ?: $dir . '/ai.sqlite');
        $db->purgeOlderThan($cfgObj->int('AI_RETENTION_DAYS', 90, 1, 3650));
    }
} catch (Throwable $e) { $db = null; }
$store = (bool)($conf['storeChats'] ?? true) && $db !== null;

try {
    $fetcher = new SafeFetch(null, $cfgObj->int('AI_WEB_TIMEOUT_SECONDS', 8, 2, 30), 1572864, $cfgObj->bool('AI_FETCH_ALLOW_HTTP', false));
    (new Controller($cfgObj, $db, $fetcher, new Log($dir)))->run($body, $st, $flushJob, $cancelFile, [
        'user_id' => $userId, 'conversation_id' => $convId, 'is_admin' => $isAdmin, 'store' => $store, 'rid' => $id,
    ]);
} catch (Throwable $e) {
    $st['status'] = 'error';
    $st['error'] = 'Interne fout bij het verwerken van dit antwoord.';
    $st['ended'] = microtime(true);
    $flushJob(true);
}

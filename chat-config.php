<?php
/**
 * Gedeelde instellingen voor de chatpagina (index.html).
 *
 * GET  -> { ok, configured, config }          (voor iedereen leesbaar)
 * POST -> { action: verify|save|clear, password, newPassword?, config? }
 *
 * De instellingen staan in storage/chat-config.php (wordt niet door de
 * FTP-deploy overschreven of verwijderd). Het bestand begint met een PHP-guard
 * zodat het nooit als tekst kan worden opgevraagd. Het wachtwoord wordt alleen
 * als hash bewaard. De API-sleutel wordt bewust niet gedeeld.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const GUARD = "<?php http_response_code(404); exit; ?>\n";
const MIN_PW = 8;

// Toegestane velden: naam => type (s = tekst, n = leeg of getal, b = boolean) en maximale lengte.
const FIELDS = [
    'baseUrl' => ['s', 300], 'model' => ['s', 300], 'system' => ['s', 20000],
    'temperature' => ['n', 20], 'top_p' => ['n', 20], 'top_k' => ['n', 20], 'min_p' => ['n', 20],
    'max_tokens' => ['n', 20], 'frequency_penalty' => ['n', 20], 'presence_penalty' => ['n', 20],
    'repeat_penalty' => ['n', 20], 'seed' => ['n', 20], 'historyLimit' => ['n', 20],
    'stop' => ['s', 2000], 'extraBody' => ['s', 20000],
    'stream' => ['b', 0], 'noThink' => ['b', 0], 'serverRun' => ['b', 0],
];

$dir  = __DIR__ . '/storage';
$file = $dir . '/chat-config.php';
$fails = $dir . '/chat-fails.json';

function out(array $d, int $code = 200): never {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function load(string $file): ?array {
    if (!is_file($file)) return null;
    $raw = @file_get_contents($file);
    if ($raw === false || strncmp($raw, GUARD, strlen(GUARD)) !== 0) return null;
    $j = json_decode(substr($raw, strlen(GUARD)), true);
    return is_array($j) && isset($j['hash'], $j['config']) ? $j : null;
}

function save(string $dir, string $file, array $data): void {
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) out(['ok' => false, 'error' => 'Kan opslagmap niet aanmaken.'], 500);
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) @file_put_contents($ht, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, GUARD . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false || !@rename($tmp, $file)) {
        @unlink($tmp);
        out(['ok' => false, 'error' => 'Opslaan op de server is mislukt (schrijfrechten?).'], 500);
    }
}

function clean(array $in): array {
    $c = [];
    foreach (FIELDS as $k => [$t, $max]) {
        if (!array_key_exists($k, $in)) continue;
        $v = $in[$k];
        if ($t === 'b') { $c[$k] = (bool)$v; continue; }
        if (is_int($v) || is_float($v)) $v = (string)$v;
        if (!is_string($v) || strlen($v) > $max) out(['ok' => false, 'error' => "Ongeldige waarde voor $k."], 400);
        if ($t === 'n' && trim($v) !== '' && !is_numeric($v)) out(['ok' => false, 'error' => "$k moet een getal zijn."], 400);
        $c[$k] = $v;
    }
    if (!empty($c['extraBody']) && trim($c['extraBody']) !== '') {
        $x = json_decode($c['extraBody'], true);
        if (!is_array($x)) out(['ok' => false, 'error' => 'Extra JSON is geen geldig JSON-object.'], 400);
    }
    return $c;
}

// Optionele API-sleutel voor chat-run.php; wordt alleen op de server bewaard en nooit teruggegeven.
function apiKey(array $in, string $old): string {
    $k = $in['apiKey'] ?? null;
    return is_string($k) && trim($k) !== '' && strlen($k) <= 500 ? trim($k) : $old;
}

$stored = load($file);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    out(['ok' => true, 'configured' => $stored !== null, 'config' => $stored['config'] ?? new stdClass()]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['ok' => false, 'error' => 'Methode niet toegestaan.'], 405);

$raw = file_get_contents('php://input', false, null, 0, 200000);
$in = json_decode((string)$raw, true);
if (!is_array($in)) out(['ok' => false, 'error' => 'Ongeldig verzoek.'], 400);
$action = $in['action'] ?? '';
$pw = is_string($in['password'] ?? null) ? $in['password'] : '';

// Eenvoudige rem tegen gokken: max. 5 foute pogingen per IP per 10 minuten.
$ipKey = sha1($_SERVER['REMOTE_ADDR'] ?? '');
$log = is_file($fails) ? (json_decode((string)@file_get_contents($fails), true) ?: []) : [];
$now = time();
foreach ($log as $k => $times) { $log[$k] = array_values(array_filter($times, fn($t) => $t > $now - 600)); if (!$log[$k]) unset($log[$k]); }
if (count($log[$ipKey] ?? []) >= 5) out(['ok' => false, 'error' => 'Te veel pogingen. Probeer het over een paar minuten opnieuw.'], 429);
$fail = function () use (&$log, $ipKey, $now, $fails, $dir) {
    $log[$ipKey][] = $now;
    if (is_dir($dir)) @file_put_contents($fails, json_encode($log), LOCK_EX);
    usleep(600000);
    out(['ok' => false, 'error' => 'Onjuist wachtwoord.'], 403);
};

if ($stored === null) {
    // Eerste keer: het meegestuurde wachtwoord wordt het beheerderswachtwoord.
    if ($action !== 'save') out(['ok' => false, 'error' => 'Nog niet ingesteld.'], 404);
    if (strlen($pw) < MIN_PW) out(['ok' => false, 'error' => 'Kies een wachtwoord van minstens ' . MIN_PW . ' tekens.'], 400);
    $cfg = clean(is_array($in['config'] ?? null) ? $in['config'] : []);
    save($dir, $file, ['hash' => password_hash($pw, PASSWORD_DEFAULT), 'config' => $cfg, 'apiKey' => apiKey($in, '')]);
    out(['ok' => true, 'configured' => true, 'config' => $cfg]);
}

if ($pw === '' || !password_verify($pw, $stored['hash'])) $fail();

if ($action === 'verify') out(['ok' => true]);

if ($action === 'save') {
    $cfg = clean(is_array($in['config'] ?? null) ? $in['config'] : []);
    $hash = $stored['hash'];
    $new = is_string($in['newPassword'] ?? null) ? $in['newPassword'] : '';
    if ($new !== '') {
        if (strlen($new) < MIN_PW) out(['ok' => false, 'error' => 'Nieuw wachtwoord moet minstens ' . MIN_PW . ' tekens hebben.'], 400);
        $hash = password_hash($new, PASSWORD_DEFAULT);
    }
    save($dir, $file, ['hash' => $hash, 'config' => $cfg, 'apiKey' => apiKey($in, (string)($stored['apiKey'] ?? ''))]);
    out(['ok' => true, 'configured' => true, 'config' => $cfg]);
}

if ($action === 'clear') {
    @unlink($file);
    out(['ok' => true, 'configured' => false]);
}

out(['ok' => false, 'error' => 'Onbekende actie.'], 400);

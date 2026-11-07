<?php
declare(strict_types=1);
namespace Rv\Ai;
defined('RV_AI') or exit;

/**
 * De zes tools. Het model kiest nooit gebruikers, rechten of operaties buiten de allowlist:
 * naam en argumenten worden gevalideerd, gebruikers-ID en rol komen uit de PHP-backend ($ctx).
 */
final class Tools
{
    public const NAMES = ['get_datetime', 'web_search', 'fetch_page', 'memory_search', 'database_query', 'get_system_status'];

    private const WEEKDAYS = ['zondag', 'maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag'];
    private const MONTHS = ['', 'januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];

    /** Toegestane leesoperaties voor database_query: operatie => toegestane parameters. */
    private const DB_OPERATIONS = [
        'conversations.list_recent' => ['limit' => ['int', 1, 20, 10]],
        'conversations.stats' => [],
    ];

    private Config $cfg;
    private ?Db $db;
    private SafeFetch $fetcher;
    private Log $log;
    /** @var callable():\DateTimeImmutable|null */
    private $clock;
    /** Per verzoek: wie vraagt, welk gesprek, welke rol en welke URL's mag fetch_page ophalen. */
    private array $ctx;

    public function __construct(Config $cfg, ?Db $db, SafeFetch $fetcher, Log $log, array $ctx = [], ?callable $clock = null)
    {
        $this->cfg = $cfg; $this->db = $db; $this->fetcher = $fetcher; $this->log = $log; $this->clock = $clock;
        $this->ctx = $ctx + ['user_id' => null, 'conversation_id' => null, 'is_admin' => false, 'allowed_urls' => [], 'rid' => bin2hex(random_bytes(4))];
    }

    public function allowedUrls(): array { return $this->ctx['allowed_urls']; }
    public function allowUrl(string $u): void { $this->ctx['allowed_urls'][self::normUrl($u)] = $u; }

    public static function normUrl(string $u): string
    {
        $u = preg_replace('/#.*$/', '', trim($u)) ?? $u;
        return rtrim(strtolower(substr($u, 0, 8)) === 'https://' ? 'https://' . substr($u, 8) : $u, '/');
    }

    public function now(): \DateTimeImmutable
    {
        $tz = new \DateTimeZone($this->cfg->get('AI_TIMEZONE', 'Europe/Amsterdam') ?: 'Europe/Amsterdam');
        return $this->clock ? ($this->clock)()->setTimezone($tz) : new \DateTimeImmutable('now', $tz);
    }

    /** Tooldefinities in OpenAI-formaat (voor native tool calling). */
    public function definitions(array $names): array
    {
        $d = [
            'get_datetime' => ['Geeft de actuele datum en tijd van de serverklok in een tijdzone.', ['timezone' => ['type' => 'string', 'description' => 'IANA-tijdzone, bijv. Europe/Amsterdam']], []],
            'web_search' => ['Zoekt live op internet en geeft titels, URL\'s en korte tekstfragmenten.', ['query' => ['type' => 'string', 'description' => 'Zoekopdracht'], 'max_results' => ['type' => 'integer', 'description' => '1 tot 8'], 'language' => ['type' => 'string', 'description' => 'nl, en of de']], ['query']],
            'fetch_page' => ['Leest de tekst van een eerder gevonden openbare webpagina (alleen URL\'s uit zoekresultaten of van de gebruiker).', ['url' => ['type' => 'string'], 'max_chars' => ['type' => 'integer', 'description' => '500 tot 12000']], ['url']],
            'memory_search' => ['Zoekt in eerdere gesprekken van deze gebruiker.', ['query' => ['type' => 'string'], 'limit' => ['type' => 'integer', 'description' => '1 tot 10']], ['query']],
            'database_query' => ['Voert een toegestane leesoperatie uit op de gegevens van deze gebruiker.', ['operation' => ['type' => 'string', 'enum' => array_keys(self::DB_OPERATIONS)], 'parameters' => ['type' => 'object']], ['operation']],
            'get_system_status' => ['Toont of de lokale AI-dienst bereikbaar is.', ['detail' => ['type' => 'string', 'enum' => ['basic', 'full']]], []],
        ];
        $out = [];
        foreach ($names as $n) {
            if (!isset($d[$n])) continue;
            $out[] = ['type' => 'function', 'function' => ['name' => $n, 'description' => $d[$n][0], 'parameters' => ['type' => 'object', 'properties' => $d[$n][1], 'required' => $d[$n][2], 'additionalProperties' => false]]];
        }
        return $out;
    }

    /**
     * Voert één tool uit. Gooit nooit: fouten komen terug als ['success'=>false,'error'=>[code,message]].
     * @param array|string $args
     */
    public function run(string $name, $args): array
    {
        $t0 = microtime(true);
        try {
            if (!in_array($name, self::NAMES, true)) throw new ToolError('unknown_tool', 'Onbekende tool.');
            if (is_string($args)) {
                $args = trim($args) === '' ? [] : json_decode($args, true);
                if (!is_array($args)) throw new ToolError('invalid_arguments', 'De argumenten zijn geen geldig JSON-object.');
            }
            if (!is_array($args) || ($args && array_keys($args) === range(0, count($args) - 1))) throw new ToolError('invalid_arguments', 'De argumenten moeten een object zijn.');
            $res = $this->{'tool_' . $name}($args);
            $ok = true; $code = null;
        } catch (ToolError $e) {
            $res = ['success' => false, 'error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]];
            $ok = false; $code = $e->errorCode;
        } catch (\Throwable $e) {
            $res = ['success' => false, 'error' => ['code' => 'internal_error', 'message' => 'Er ging iets mis bij het uitvoeren van deze tool.']];
            $ok = false; $code = 'internal_error';
        }
        $ms = (int)round((microtime(true) - $t0) * 1000);
        $res['_ms'] = $ms;
        $this->log->tool((string)$this->ctx['rid'], in_array($name, self::NAMES, true) ? $name : 'unknown', $ms, $ok, $code, $res['provider'] ?? null);
        return $res;
    }

    /* ------------------------------------------------------------------ validatie */

    private function only(array $args, array $allowed): void
    {
        foreach ($args as $k => $_) if (!in_array($k, $allowed, true)) throw new ToolError('invalid_arguments', 'Onbekend argument: ' . substr((string)$k, 0, 40));
    }

    private function int(array $args, string $k, int $min, int $max, int $default): int
    {
        if (!array_key_exists($k, $args) || $args[$k] === null) return $default;
        $v = $args[$k];
        if (is_string($v) && preg_match('/^-?\d+$/', $v)) $v = (int)$v;
        if (!is_int($v) || $v < $min || $v > $max) throw new ToolError('invalid_arguments', "$k moet een geheel getal van $min tot $max zijn.");
        return $v;
    }

    private function str(array $args, string $k, int $min, int $max, bool $required = true): ?string
    {
        if (!array_key_exists($k, $args) || $args[$k] === null || $args[$k] === '') {
            if ($required) throw new ToolError('invalid_arguments', "$k is verplicht.");
            return null;
        }
        if (!is_string($args[$k])) throw new ToolError('invalid_arguments', "$k moet tekst zijn.");
        $v = trim(HtmlText::clean($args[$k]));
        if (mb_strlen($v) < $min || mb_strlen($v) > $max) throw new ToolError('invalid_arguments', "$k moet $min tot $max tekens lang zijn.");
        return $v;
    }

    private function rate(string $what): void
    {
        if ($this->db === null) return;
        $who = (string)($this->ctx['user_id'] ?? 'anon');
        $limit = $this->cfg->int('AI_RATE_SEARCH_PER_MIN', 10, 1, 120);
        if (!$this->db->rateHit($what . ':' . $who, $limit, 60)) throw new ToolError('rate_limited', 'Te veel verzoeken in korte tijd. Probeer het zo opnieuw.');
    }

    /* ------------------------------------------------------------------ tools */

    private function tool_get_datetime(array $a): array
    {
        $this->only($a, ['timezone']);
        $tzName = $this->str($a, 'timezone', 3, 64, false) ?? ($this->cfg->get('AI_TIMEZONE', 'Europe/Amsterdam') ?: 'Europe/Amsterdam');
        if (!in_array($tzName, \DateTimeZone::listIdentifiers(), true)) throw new ToolError('invalid_timezone', 'Onbekende tijdzone.');
        $now = $this->clock ? ($this->clock)() : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $now = $now->setTimezone(new \DateTimeZone($tzName));
        return [
            'success' => true, 'timezone' => $tzName, 'iso8601' => $now->format('c'), 'date' => $now->format('Y-m-d'), 'time' => $now->format('H:i:s'),
            'weekday_nl' => self::WEEKDAYS[(int)$now->format('w')], 'month_nl' => self::MONTHS[(int)$now->format('n')], 'week_number' => (int)$now->format('W'), 'source' => 'server_clock',
        ];
    }

    private function tool_web_search(array $a): array
    {
        $this->only($a, ['query', 'max_results', 'language']);
        $q = $this->str($a, 'query', 2, 200);
        $max = $this->int($a, 'max_results', 1, 8, 5);
        $lang = $this->str($a, 'language', 2, 5, false) ?? 'nl';
        if (!preg_match('/^[a-z]{2}(-[A-Za-z]{2})?$/', $lang)) throw new ToolError('invalid_arguments', 'language is ongeldig.');
        $provider = strtolower($this->cfg->get('SEARCH_PROVIDER', '') ?? '');
        $timeout = $this->cfg->int('AI_WEB_TIMEOUT_SECONDS', 8, 2, 30);
        $this->rate('search');

        if ($provider === 'brave') {
            $key = $this->cfg->get('BRAVE_SEARCH_API_KEY');
            if (!$key) throw new ToolError('search_unconfigured', 'Live zoeken is niet geconfigureerd (API-sleutel ontbreekt).');
            $base = rtrim($this->cfg->get('BRAVE_SEARCH_BASE_URL', 'https://api.search.brave.com') ?? '', '/');
            $r = Http::get($base . '/res/v1/web/search?' . http_build_query(['q' => $q, 'count' => min(20, $max * 2), 'search_lang' => $lang]), ['Accept: application/json', 'X-Subscription-Token: ' . $key], $timeout, 1);
            $items = [];
            $j = $this->decode($r, 'Brave Search');
            foreach (($j['web']['results'] ?? []) as $x) $items[] = ['title' => $x['title'] ?? '', 'url' => $x['url'] ?? '', 'snippet' => $x['description'] ?? '', 'published_at' => $x['page_age'] ?? null];
        } elseif ($provider === 'searxng') {
            $base = rtrim($this->cfg->get('SEARXNG_BASE_URL', '') ?? '', '/');
            if (!preg_match('#^https?://#i', $base)) throw new ToolError('search_unconfigured', 'Live zoeken is niet geconfigureerd (SearXNG-adres ontbreekt).');
            $r = Http::get($base . '/search?' . http_build_query(['q' => $q, 'format' => 'json', 'language' => $lang, 'pageno' => 1]), ['Accept: application/json'], $timeout, 1);
            $items = [];
            $j = $this->decode($r, 'SearXNG');
            foreach (($j['results'] ?? []) as $x) $items[] = ['title' => $x['title'] ?? '', 'url' => $x['url'] ?? '', 'snippet' => $x['content'] ?? '', 'published_at' => $x['publishedDate'] ?? null];
        } else {
            throw new ToolError('search_unconfigured', 'Live zoeken is niet geconfigureerd (geen zoekprovider ingesteld).');
        }

        $results = []; $seen = [];
        foreach ($items as $x) {
            $url = is_string($x['url']) ? trim($x['url']) : '';
            if (!preg_match('#^https?://[^\s]+$#i', $url) || strlen($url) > 2048) continue;               // verdachte schema's (javascript:, data:, file:) vallen weg
            $title = mb_substr(HtmlText::clean(strip_tags((string)$x['title'])), 0, 160);
            $snip = mb_substr(HtmlText::clean(html_entity_decode(strip_tags((string)$x['snippet']), ENT_QUOTES | ENT_HTML5, 'UTF-8')), 0, 300);
            if ($title === '' && $snip === '') continue;
            $key = strtolower(preg_replace('#^https?://(www\.)?#i', '', rtrim(preg_replace('/[?#].*$/', '', $url) ?? $url, '/')) ?? $url);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $pub = null;
            if (is_string($x['published_at']) && preg_match('/^\d{4}-\d{2}-\d{2}/', $x['published_at']) && strtotime($x['published_at']) !== false) $pub = $x['published_at'];
            $results[] = ['title' => $title !== '' ? $title : $url, 'url' => $url, 'snippet' => $snip, 'published_at' => $pub];
            $this->allowUrl($url);
            if (count($results) >= $max) break;
        }
        return ['success' => true, 'query' => $q, 'searched_at' => $this->now()->format('c'), 'provider' => $provider, 'results' => $results];
    }

    private function decode(array $r, string $name): array
    {
        if ($r['error'] !== null) throw new ToolError('search_failed', "$name is niet bereikbaar ($r[error]).");
        if ($r['code'] === 401 || $r['code'] === 403) throw new ToolError('search_auth', "$name weigerde de toegang (controleer de API-sleutel).");
        if ($r['code'] === 429) throw new ToolError('search_rate', "$name heeft de limiet bereikt. Probeer het later opnieuw.");
        if ($r['code'] < 200 || $r['code'] >= 300) throw new ToolError('search_failed', "$name gaf HTTP $r[code] terug.");
        $j = json_decode($r['body'], true);
        if (!is_array($j)) throw new ToolError('search_failed', "$name gaf een onleesbaar antwoord.");
        return $j;
    }

    private function tool_fetch_page(array $a): array
    {
        $this->only($a, ['url', 'max_chars']);
        $url = $this->str($a, 'url', 8, 2048);
        $max = $this->int($a, 'max_chars', 500, 12000, 6000);
        if (!isset($this->ctx['allowed_urls'][self::normUrl($url)])) throw new ToolError('url_not_allowed', 'Alleen URL\'s uit zoekresultaten of uit het bericht van de gebruiker mogen worden opgehaald.');
        $this->rate('fetch');
        $r = $this->fetcher->fetch($url, $max);
        $this->allowUrl($r['url']);
        return ['success' => true, 'url' => $r['url'], 'requested_url' => $r['requested_url'], 'fetched_at' => $this->now()->format('c'), 'title' => $r['title'], 'text' => $r['text'], 'truncated' => $r['truncated']];
    }

    private function tool_memory_search(array $a): array
    {
        $this->only($a, ['query', 'limit']);
        $q = $this->str($a, 'query', 2, 200);
        $limit = $this->int($a, 'limit', 1, 10, 5);
        $uid = $this->ctx['user_id'];
        if ($this->db === null || !is_string($uid) || $uid === '') throw new ToolError('memory_unavailable', 'Het geheugen is niet beschikbaar.');
        return ['success' => true, 'note' => 'Herinneringen uit eerdere gesprekken; ze kunnen verouderd zijn en zijn geen actuele feiten.', 'matches' => $this->db->searchMemory($uid, $q, $limit, $this->ctx['conversation_id'])];
    }

    private function tool_database_query(array $a): array
    {
        $this->only($a, ['operation', 'parameters']);
        $op = $this->str($a, 'operation', 3, 60);
        if (!isset(self::DB_OPERATIONS[$op])) throw new ToolError('operation_not_allowed', 'Deze operatie is niet toegestaan.');
        $params = $a['parameters'] ?? [];
        if (!is_array($params)) throw new ToolError('invalid_arguments', 'parameters moet een object zijn.');
        foreach ($params as $k => $_) if (!isset(self::DB_OPERATIONS[$op][$k])) throw new ToolError('invalid_arguments', 'Onbekende parameter: ' . substr((string)$k, 0, 40));
        $uid = $this->ctx['user_id'];
        if ($this->db === null || !is_string($uid) || $uid === '') throw new ToolError('database_unavailable', 'De database is niet beschikbaar.');
        if ($op === 'conversations.list_recent') {
            $limit = $this->int($params, 'limit', 1, 20, 10);
            $rows = array_map(static fn($r) => ['id' => $r['id'], 'title' => Db::redact($r['title']), 'updated_at' => $r['updated_at'], 'message_count' => $r['message_count']], $this->db->listConversations($uid, $limit));
        } else {
            $rows = [$this->db->stats($uid)];
        }
        return ['success' => true, 'operation' => $op, 'count' => count($rows), 'rows' => $rows];
    }

    private function tool_get_system_status(array $a): array
    {
        $this->only($a, ['detail']);
        $detail = $this->str($a, 'detail', 4, 5, false) ?? 'basic';
        if (!in_array($detail, ['basic', 'full'], true)) throw new ToolError('invalid_arguments', 'detail moet basic of full zijn.');
        $admin = (bool)$this->ctx['is_admin'];
        $base = rtrim($this->cfg->get('LM_STUDIO_BASE_URL', '') ?? '', '/');
        $reach = false; $loaded = null; $avail = null;
        if ($base !== '') {
            $r = Http::get($base . '/models', [], 4, 0);
            if ($r['error'] === null && $r['code'] >= 200 && $r['code'] < 300) {
                $j = json_decode($r['body'], true);
                if (is_array($j) && isset($j['data']) && is_array($j['data'])) {
                    $reach = true;
                    $avail = array_values(array_filter(array_map(static fn($m) => is_array($m) && isset($m['id']) ? (string)$m['id'] : null, $j['data'])));
                    // LM Studio kent ook een eigen API die per model de status laat zien
                    $origin = preg_replace('#^(https?://[^/]+).*$#', '$1', $base);
                    $r2 = Http::get($origin . '/api/v0/models', [], 4, 0);
                    $j2 = $r2['error'] === null ? json_decode($r2['body'], true) : null;
                    if (is_array($j2) && isset($j2['data']) && is_array($j2['data'])) {
                        $loaded = array_values(array_filter(array_map(static fn($m) => is_array($m) && ($m['state'] ?? '') === 'loaded' && isset($m['id']) ? (string)$m['id'] : null, $j2['data'])));
                    }
                }
            }
        }
        $out = ['success' => true, 'checked_at' => $this->now()->format('c'), 'lm_studio' => ['reachable' => $reach]];
        if (!$admin || $detail === 'basic') {
            $out['lm_studio']['model_available'] = $reach ? ((count($avail ?? []) > 0)) : false;
            return $out;
        }
        $out['lm_studio']['loaded_models'] = $loaded;       // null = onbekend
        $out['lm_studio']['available_models'] = $avail;
        $out['host'] = $this->hostStats();
        return $out;
    }

    /** CPU/RAM alleen via een geauthenticeerde statusagent op de mini-pc; anders null (nooit geschat). */
    private function hostStats(): array
    {
        $none = ['cpu_usage_percent' => null, 'memory_used_mb' => null, 'memory_total_mb' => null];
        $url = $this->cfg->get('STATUS_AGENT_URL'); $tok = $this->cfg->get('STATUS_AGENT_TOKEN');
        if (!$url || !$tok || !preg_match('#^https?://#i', $url)) return $none;
        $r = Http::get($url, ['Accept: application/json', 'Authorization: Bearer ' . $tok], 3, 0, 65536);
        if ($r['error'] !== null || $r['code'] !== 200) return $none;
        $j = json_decode($r['body'], true);
        if (!is_array($j)) return $none;
        $num = static fn($v, $lo, $hi) => (is_int($v) || is_float($v)) && $v >= $lo && $v <= $hi ? $v : null;
        return ['cpu_usage_percent' => $num($j['cpu_usage_percent'] ?? null, 0, 100), 'memory_used_mb' => $num($j['memory_used_mb'] ?? null, 0, 100000000), 'memory_total_mb' => $num($j['memory_total_mb'] ?? null, 0, 100000000)];
    }
}

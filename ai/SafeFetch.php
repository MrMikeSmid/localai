<?php
declare(strict_types=1);
namespace Rv\Ai;
defined('RV_AI') or exit;

/**
 * Haalt een publieke webpagina op, beschermd tegen SSRF:
 *  - alleen https (http alleen als dat expliciet is toegestaan), alleen poort 443 (80),
 *  - DNS wordt zelf opgelost en alle adressen moeten publiek zijn,
 *  - de verbinding wordt aan het gecontroleerde IP vastgepind (CURLOPT_RESOLVE) en het daadwerkelijk
 *    verbonden IP wordt achteraf nogmaals gecontroleerd,
 *  - elke redirect wordt opnieuw gevalideerd (max. 3),
 *  - beperkte downloadgrootte, tijd en Content-Type; HTML wordt veilig omgezet naar platte tekst.
 */
final class SafeFetch
{
    private Ssrf $ssrf;
    private int $maxBytes;
    private int $timeout;
    private bool $allowHttp;
    /** @var string[] */
    private array $types = ['text/html', 'application/xhtml+xml', 'text/plain'];

    public function __construct(?Ssrf $ssrf = null, int $timeout = 8, int $maxBytes = 1572864, bool $allowHttp = false)
    {
        $this->ssrf = $ssrf ?? new Ssrf();
        $this->timeout = max(2, $timeout);
        $this->maxBytes = $maxBytes;
        $this->allowHttp = $allowHttp;
    }

    /** @return array{url:string,requested_url:string,title:?string,text:string,truncated:bool} */
    public function fetch(string $url, int $maxChars = 6000): array
    {
        $requested = $url;
        $deadline = microtime(true) + $this->timeout + 4;
        for ($hop = 0; $hop <= 3; $hop++) {
            $p = $this->ssrf->parse($url, $this->allowHttp);
            $ips = $this->ssrf->resolvePublic($p['host']);
            $r = $this->request($p, $ips[0], max(2, (int)ceil($deadline - microtime(true))));
            if ($r['redirect'] !== null) {
                if ($hop === 3) throw new ToolError('too_many_redirects', 'Te veel doorverwijzingen.');
                $url = self::absolutize($url, $r['redirect']);
                continue;
            }
            if ($r['code'] < 200 || $r['code'] >= 300) throw new ToolError('http_error', 'De pagina gaf HTTP ' . $r['code'] . ' terug.');
            $body = $this->toUtf8($r['body'], $r['charset']);
            if ($r['type'] === 'text/plain') {
                $title = null; $text = HtmlText::clean($body, true);
            } else {
                $c = HtmlText::convert($body); $title = $c['title']; $text = $c['text'];
            }
            if ($text === '') throw new ToolError('empty_page', 'De pagina bevat geen leesbare tekst.');
            $trunc = $r['truncated'] || mb_strlen($text) > $maxChars;
            return ['url' => $url, 'requested_url' => $requested, 'title' => $title, 'text' => mb_substr($text, 0, $maxChars), 'truncated' => $trunc];
        }
        throw new ToolError('too_many_redirects', 'Te veel doorverwijzingen.');
    }

    /** @return array{code:int,body:string,type:string,charset:?string,redirect:?string,truncated:bool} */
    private function request(array $p, string $ip, int $timeout): array
    {
        $body = ''; $type = ''; $charset = null; $loc = null; $trunc = false; $reject = null; $code = 0;
        $ch = curl_init($p['url']);
        $ipLit = strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip;
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE => [$p['host'] . ':' . $p['port'] . ':' . $ipLit],
            CURLOPT_PROXY => '',   // nooit via een omgevings-proxy: de verbinding moet naar het gecontroleerde IP gaan
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => $this->allowHttp ? (CURLPROTO_HTTP | CURLPROTO_HTTPS) : CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => min(4, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'RivsennAI/1.0 (paginalezer)',
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,text/plain;q=0.8', 'Accept-Language: nl,en;q=0.8'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$type, &$charset, &$loc, &$reject, &$code) {
                $n = strlen($line);
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) { $code = (int)$m[1]; $type = ''; $loc = null; }
                elseif (stripos($line, 'content-type:') === 0) {
                    $v = trim(substr($line, 13));
                    $type = strtolower(trim(explode(';', $v)[0]));
                    if (preg_match('/charset=([\w\-]+)/i', $v, $m)) $charset = $m[1];
                } elseif (stripos($line, 'location:') === 0) $loc = trim(substr($line, 9));
                elseif (stripos($line, 'content-length:') === 0 && (int)trim(substr($line, 15)) > 20 * 1048576) $reject = 'too_large';
                return $n;
            },
            CURLOPT_WRITEFUNCTION => function ($ch, string $c) use (&$body, &$trunc, &$type, &$reject, &$code, &$loc) {
                if ($reject !== null) return 0;
                $isRedirect = $code >= 300 && $code < 400;
                if (!$isRedirect && !in_array($type, $this->types, true)) { $reject = 'content_type'; return 0; }
                if (strlen($body) + strlen($c) > $this->maxBytes) { $body .= substr($c, 0, $this->maxBytes - strlen($body)); $trunc = true; return 0; }
                $body .= $c;
                return strlen($c);
            },
        ]);
        curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $primary = (string)curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        $final = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($primary !== '' && !$this->ssrf->isAllowedIp($primary)) throw new ToolError('ip_blocked', 'De verbinding ging naar een niet-toegestaan adres en is geweigerd.');
        if ($reject === 'content_type') throw new ToolError('content_type_blocked', 'Dit type bestand kan niet worden gelezen (alleen tekst en HTML).');
        if ($reject === 'too_large') throw new ToolError('too_large', 'De pagina is te groot.');
        if ($errno !== 0 && !$trunc) {
            if ($errno === CURLE_OPERATION_TIMEDOUT) throw new ToolError('timeout', 'De pagina reageerde te traag.');
            throw new ToolError('fetch_failed', 'De pagina kon niet worden opgehaald (' . ($err !== '' ? $err : 'netwerkfout') . ').');
        }
        $code = $final ?: $code;
        $redirect = ($code >= 300 && $code < 400 && $loc !== null && $loc !== '') ? $loc : null;
        return ['code' => $code, 'body' => $body, 'type' => $type, 'charset' => $charset, 'redirect' => $redirect, 'truncated' => $trunc];
    }

    private static function absolutize(string $base, string $loc): string
    {
        if (preg_match('#^https?://#i', $loc)) return $loc;
        $b = parse_url($base);
        $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if (strncmp($loc, '//', 2) === 0) return $b['scheme'] . ':' . $loc;
        if ($loc !== '' && $loc[0] === '/') return $origin . $loc;
        $dir = rtrim(dirname($b['path'] ?? '/'), '/');
        return $origin . $dir . '/' . $loc;
    }

    private function toUtf8(string $s, ?string $charset): string
    {
        if ($charset === null && preg_match('/<meta[^>]+charset=["\']?([\w\-]+)/i', substr($s, 0, 4096), $m)) $charset = $m[1];
        $cs = strtoupper($charset ?? 'UTF-8');
        if ($cs !== 'UTF-8' && $cs !== 'UTF8') {
            $c = @mb_convert_encoding($s, 'UTF-8', $cs);
            if (is_string($c)) return $c;
        }
        return mb_check_encoding($s, 'UTF-8') ? $s : (string)mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    }
}

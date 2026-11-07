<?php
declare(strict_types=1);
namespace Rv\Ai;
defined('RV_AI') or exit;

/**
 * SSRF-bescherming: URL-validatie, DNS-resolutie en IP-controle.
 * Een instantie krijgt optioneel een eigen resolver en IP-beleid (alleen voor tests); in productie
 * worden de strikte standaardwaarden gebruikt.
 */
final class Ssrf
{
    /** @var callable(string):array */
    private $resolver;
    /** @var callable(string):bool */
    private $ipPolicy;
    /** @var int[] */
    private array $extraPorts;

    private const V4_BLOCKED = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4', '255.255.255.255/32',
    ];
    private const V6_BLOCKED = [
        '::/128', '::1/128', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8', '2001:db8::/32', '100::/64', '2001::/32',
    ];

    /** $extraPorts is uitsluitend bedoeld voor tests met een lokale testserver; productie gebruikt alleen 443 (en 80 bij toegestane http). */
    public function __construct(?callable $resolver = null, ?callable $ipPolicy = null, array $extraPorts = [])
    {
        $this->extraPorts = array_map('intval', $extraPorts);
        $this->resolver = $resolver ?? [self::class, 'dnsResolve'];
        $this->ipPolicy = $ipPolicy ?? [self::class, 'isPublicIp'];
    }

    public function isAllowedIp(string $ip): bool { return (bool)($this->ipPolicy)($ip); }

    /** Standaard IP-beleid: alleen globaal routeerbare adressen. */
    public static function isPublicIp(string $ip): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) return false;
        if (strlen($bin) === 4) {
            foreach (self::V4_BLOCKED as $c) if (self::inCidr($bin, $c)) return false;
            return true;
        }
        // IPv4 ingebed in IPv6: controleer het v4-adres
        if (strncmp($bin, str_repeat("\0", 10) . "\xff\xff", 12) === 0) return self::isPublicIp(inet_ntop(substr($bin, 12)));   // ::ffff:a.b.c.d
        if (strncmp($bin, str_repeat("\0", 12), 12) === 0 && $bin !== str_repeat("\0", 16)) return false;                      // ::a.b.c.d (verouderd)
        if (strncmp($bin, hex2bin('0064ff9b') . str_repeat("\0", 8), 12) === 0) return self::isPublicIp(inet_ntop(substr($bin, 12))); // 64:ff9b::/96 NAT64
        if (strncmp($bin, hex2bin('2002'), 2) === 0) return self::isPublicIp(inet_ntop(substr($bin, 2, 4)));                    // 6to4
        foreach (self::V6_BLOCKED as $c) if (self::inCidr($bin, $c)) return false;
        return true;
    }

    private static function inCidr(string $bin, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $nb = inet_pton($net);
        if ($nb === false || strlen($nb) !== strlen($bin)) return false;
        $bits = (int)$bits;
        $full = intdiv($bits, 8);
        if ($full > 0 && strncmp($bin, $nb, $full) !== 0) return false;
        $rem = $bits % 8;
        if ($rem === 0) return true;
        $mask = (0xFF << (8 - $rem)) & 0xFF;
        return ((ord($bin[$full]) & $mask) === (ord($nb[$full]) & $mask));
    }

    /** @return string[] */
    public static function dnsResolve(string $host): array
    {
        $ips = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $r) {
            if (!empty($r['ip'])) $ips[] = $r['ip'];
            if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
        }
        if (!$ips) { $v4 = @gethostbynamel($host); if ($v4) $ips = $v4; }
        return array_values(array_unique($ips));
    }

    /**
     * Valideert een URL en geeft de onderdelen terug.
     * @return array{scheme:string,host:string,port:int,url:string,path:string}
     */
    public function parse(string $url, bool $allowHttp = false): array
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) throw new ToolError('invalid_url', 'Ongeldige URL.');
        $p = parse_url($url);
        if (!is_array($p) || empty($p['scheme']) || empty($p['host'])) throw new ToolError('invalid_url', 'Ongeldige URL.');
        $scheme = strtolower($p['scheme']);
        if ($scheme !== 'https' && !($allowHttp && $scheme === 'http')) throw new ToolError('scheme_blocked', 'Alleen https-adressen zijn toegestaan.');
        if (isset($p['user']) || isset($p['pass'])) throw new ToolError('url_blocked', 'Adressen met inloggegevens zijn niet toegestaan.');
        $host = strtolower(rtrim($p['host'], '.'));
        $host = trim($host, '[]');
        $port = $p['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (!($scheme === 'https' && $port === 443) && !($scheme === 'http' && $port === 80) && !in_array($port, $this->extraPorts, true)) throw new ToolError('port_blocked', 'Deze poort is niet toegestaan.');
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            // alleen gewone domeinnamen, geen interne hostnamen
            if (!preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host) && !preg_match('/^xn--/', $host)) {
                throw new ToolError('host_blocked', 'Deze hostnaam is niet toegestaan.');
            }
            if (preg_match('/(^|\.)(localhost|local|internal|intranet|lan|home|corp|private|home\.arpa|localdomain)$/', $host)) {
                throw new ToolError('host_blocked', 'Interne hostnamen zijn niet toegestaan.');
            }
        }
        $path = ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
        return ['scheme' => $scheme, 'host' => $host, 'port' => $port, 'url' => $url, 'path' => $path];
    }

    /**
     * Resolve de host en eis dat ALLE adressen publiek zijn. Geeft de adressen terug waaruit gekozen mag worden.
     * @return string[]
     */
    public function resolvePublic(string $host): array
    {
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : (array)($this->resolver)($host);
        if (!$ips) throw new ToolError('dns_failed', 'De hostnaam kon niet worden opgezocht.');
        foreach ($ips as $ip) {
            if (!$this->isAllowedIp((string)$ip)) throw new ToolError('ip_blocked', 'Dit adres verwijst naar een intern of gereserveerd netwerk en is geweigerd.');
        }
        return array_values($ips);
    }
}

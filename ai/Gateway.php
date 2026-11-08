<?php
declare(strict_types=1);
namespace Rv\Ai;
defined('RV_AI') or exit;

/**
 * Verbinding met de modelserver (Ollama of LM Studio) via een OpenAI-compatibele API, meestal achter een
 * https-gateway met Bearer-authenticatie. De API-sleutel wordt alleen hier op de server gebruikt en nooit
 * teruggegeven of gelogd.
 */
final class Gateway
{
    /** @return array{0:string,1:string} [code, Nederlandse melding] voor een HTTP-status van de gateway/modelserver */
    public static function describe(int $status, string $detail = ''): array
    {
        $d = trim(preg_replace('/\s+/', ' ', $detail) ?? '');
        $modelMissing = preg_match('/model\s+[\'"“]?([^\'"”\s]+)[\'"”]?\s+not found/i', $d, $m) === 1;
        switch (true) {
            case $status === 401:
                return ['unauthorized', 'De gateway weigerde de toegang (HTTP 401): de API-sleutel ontbreekt of is onjuist.'];
            case $status === 403:
                return ['forbidden', 'Toegang geweigerd (HTTP 403): de sleutel heeft hier geen rechten, of het adres wordt geblokkeerd.'];
            case $status === 404 && $modelMissing:
                return ['model_not_found', "Het model '" . $m[1] . "' bestaat niet op de server (HTTP 404). Controleer de modelnaam, bijvoorbeeld met 'ollama list' (zoals qwen3:1.7b)."];
            case $status === 404:
                return ['not_found', 'Niet gevonden (HTTP 404): controleer of het adres op /v1 eindigt en of het model bestaat.'];
            case $status === 429:
                return ['rate_limited', 'Te veel verzoeken (HTTP 429): de gateway of server begrenst het gebruik. Probeer het zo opnieuw.'];
            case $status === 502:
                return ['bad_gateway', 'De gateway kan Ollama niet bereiken (HTTP 502). Draait Ollama op de server en klopt het poortadres achter de gateway?'];
            case $status === 503:
                return ['unavailable', 'De server is tijdelijk niet beschikbaar (HTTP 503). Het model wordt mogelijk nog geladen; probeer het zo opnieuw.'];
            case $status === 504:
                return ['gateway_timeout', 'De gateway kreeg te laat antwoord van het model (HTTP 504). Probeer het opnieuw of kies een kleiner model.'];
            case $status >= 500:
                return ['server_error', "De modelserver gaf een fout (HTTP $status)" . ($d !== '' ? ': ' . mb_substr($d, 0, 160) : '.')];
            default:
                return ['http_error', "Onverwacht antwoord (HTTP $status)" . ($d !== '' ? ': ' . mb_substr($d, 0, 160) : '.')];
        }
    }

    /** Melding bij een netwerkfout (geen HTTP-antwoord). */
    public static function describeNetwork(int $errno, int $timeout): array
    {
        if ($errno === 28) return ['timeout', "Time-out: de server reageerde niet binnen $timeout seconden."];
        if ($errno === 6) return ['dns', 'De hostnaam van de server kon niet worden gevonden. Controleer het adres.'];
        if ($errno === 7) return ['connect', 'Geen verbinding mogelijk: de server weigert de verbinding of staat uit.'];
        if (in_array($errno, [35, 51, 58, 60, 77], true)) return ['tls', 'De beveiligde (https) verbinding mislukte: het certificaat of de https-instelling van de gateway klopt niet.'];
        return ['network', 'De server is niet bereikbaar.'];
    }

    /** Haalt een foutmelding uit een JSON-foutantwoord van Ollama/OpenAI. */
    public static function errorDetail(string $raw): string
    {
        $j = json_decode($raw, true);
        if (is_array($j)) {
            $e = $j['error'] ?? null;
            if (is_array($e)) return (string)($e['message'] ?? '');
            if (is_string($e)) return $e;
        }
        return mb_substr(strip_tags($raw), 0, 200);
    }

    /** Verwijdert de sleutel uit tekst (zou nooit in een melding mogen staan, dit is een vangnet). */
    public static function scrub(string $text, string $key): string
    {
        return $key !== '' ? str_replace($key, '***', $text) : $text;
    }

    /**
     * Verbindingstest: GET {base}/models met Bearer. Bij succes (HTTP 200) komen de beschikbare modellen terug.
     * @return array{ok:bool,status:int,ms:int,models:string[],model_found:?bool,error:?array{code:string,message:string}}
     */
    public static function test(string $base, string $key, string $model = '', int $timeout = 8): array
    {
        $t0 = microtime(true);
        $base = rtrim($base, '/');
        $out = ['ok' => false, 'status' => 0, 'ms' => 0, 'models' => [], 'model_found' => null, 'error' => null];
        if (!preg_match('#^https?://#i', $base)) {
            $out['error'] = ['code' => 'invalid_url', 'message' => 'Geen geldig adres (het moet met https:// beginnen).'];
            return $out;
        }
        $headers = ['Accept: application/json'];
        if ($key !== '') $headers[] = 'Authorization: Bearer ' . $key;
        $r = Http::get($base . '/models', $headers, $timeout, 0);
        $out['ms'] = (int)round((microtime(true) - $t0) * 1000);
        $out['status'] = $r['code'];
        if ($r['error'] !== null && $r['code'] === 0) {
            [$c, $m] = self::describeNetwork($r['errno'], $timeout);
            $out['error'] = ['code' => $c, 'message' => $m];
            return $out;
        }
        if ($r['code'] !== 200) {
            [$c, $m] = self::describe($r['code'], self::scrub(self::errorDetail($r['body']), $key));
            $out['error'] = ['code' => $c, 'message' => $m];
            return $out;
        }
        $j = json_decode($r['body'], true);
        if (!is_array($j) || !isset($j['data']) || !is_array($j['data'])) {
            $out['error'] = ['code' => 'bad_response', 'message' => 'De server antwoordde, maar niet met een OpenAI-compatibele modellenlijst. Controleer of het adres op /v1 eindigt.'];
            return $out;
        }
        foreach ($j['data'] as $m) if (is_array($m) && isset($m['id'])) $out['models'][] = (string)$m['id'];
        $out['ok'] = true;
        if ($model !== '') $out['model_found'] = in_array($model, $out['models'], true);
        return $out;
    }
}

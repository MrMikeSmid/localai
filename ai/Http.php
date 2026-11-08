<?php
declare(strict_types=1);
namespace Rv\Ai;
defined('RV_AI') or exit;

/** Kleine HTTP-hulp voor vertrouwde, door de beheerder geconfigureerde diensten (zoek-API, modelserver, statusagent). */
final class Http
{
    /**
     * GET met korte timeout en beperkte retry (alleen bij netwerkfouten en 5xx).
     * @return array{code:int,body:string,error:?string,errno:int}
     */
    public static function get(string $url, array $headers = [], int $timeout = 8, int $retries = 1, int $maxBytes = 2097152): array
    {
        $last = ['code' => 0, 'body' => '', 'error' => 'onbekend', 'errno' => 0];
        for ($i = 0; $i <= $retries; $i++) {
            $body = '';
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CONNECTTIMEOUT => min(4, $timeout),
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_ENCODING => '',
                CURLOPT_WRITEFUNCTION => static function ($ch, string $c) use (&$body, $maxBytes) {
                    if (strlen($body) + strlen($c) > $maxBytes) return 0;
                    $body .= $c;
                    return strlen($c);
                },
            ]);
            curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $errno = curl_errno($ch);
            $err = $errno ? curl_error($ch) : null;
            curl_close($ch);
            $last = ['code' => $code, 'body' => $body, 'error' => $err, 'errno' => $errno];
            if ($err === null && $code < 500) break;
            if ($i < $retries) usleep(250000);
        }
        return $last;
    }
}

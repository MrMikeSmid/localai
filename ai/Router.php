<?php
declare(strict_types=1);
namespace Rv\Ai;
defined('RV_AI') or exit;

/**
 * Deterministische intentrouter voor kleine modellen. Beslist uitsluitend op basis van de tekst van de
 * gebruiker (nooit op modeluitvoer of toolresultaten) welke tools worden uitgevoerd.
 */
final class Router
{
    private const TZ_CITIES = [
        'new york' => 'America/New_York', 'los angeles' => 'America/Los_Angeles', 'chicago' => 'America/Chicago',
        'londen' => 'Europe/London', 'london' => 'Europe/London', 'parijs' => 'Europe/Paris', 'berlijn' => 'Europe/Berlin',
        'madrid' => 'Europe/Madrid', 'rome' => 'Europe/Rome', 'moskou' => 'Europe/Moscow', 'dubai' => 'Asia/Dubai',
        'tokio' => 'Asia/Tokyo', 'tokyo' => 'Asia/Tokyo', 'peking' => 'Asia/Shanghai', 'beijing' => 'Asia/Shanghai', 'shanghai' => 'Asia/Shanghai',
        'singapore' => 'Asia/Singapore', 'sydney' => 'Australia/Sydney', 'mumbai' => 'Asia/Kolkata', 'delhi' => 'Asia/Kolkata',
        'sao paulo' => 'America/Sao_Paulo', 'toronto' => 'America/Toronto', 'paramaribo' => 'America/Paramaribo', 'curaçao' => 'America/Curacao', 'curacao' => 'America/Curacao',
    ];

    /** @return string[] URL's (http/https) in de tekst, ontdubbeld, maximaal 2 */
    public static function urls(string $text): array
    {
        preg_match_all('#https?://[^\s<>"\'\)\]]+#iu', $text, $m);
        $out = [];
        foreach ($m[0] as $u) { $u = rtrim($u, '.,;:!?'); $out[$u] = true; }
        return array_slice(array_keys($out), 0, 2);
    }

    /**
     * @return array<int,array{0:string,1:array}> lijst van [toolnaam, argumenten], maximaal 3
     */
    public static function plan(string $text): array
    {
        $t = mb_strtolower($text);
        $plan = [];

        if (preg_match('/\b(hoe laat|welke dag|wat voor dag|welke datum|datum van vandaag|de hoeveelste|wat is de datum|welke maand|welk jaar|weeknummer|hoe laat is het|tijdzone|tijdsverschil)\b|\bwat is het (?:nu )?(?:voor )?(?:een )?(?:datum|dag|tijd)\b|\b(vandaag|morgen|overmorgen|gisteren|eergisteren)\b.{0,30}\b(datum|dag|weekdag)\b|\b(?:welke|wat voor) dag (?:is|wordt|was) (?:het )?(?:morgen|overmorgen|gisteren|vandaag)\b|\bvolgende (?:week|maandag|dinsdag|woensdag|donderdag|vrijdag|zaterdag|zondag)\b|\bhoeveel (?:dagen|weken) (?:tot|nog tot|is het nog)\b/iu', $t)) {
            $args = [];
            foreach (self::TZ_CITIES as $city => $tz) if (preg_match('/\b' . preg_quote($city, '/') . '\b/u', $t)) { $args['timezone'] = $tz; break; }
            $plan[] = ['get_datetime', $args];
        }

        $urls = self::urls($text);
        foreach ($urls as $u) $plan[] = ['fetch_page', ['url' => $u]];

        if (!$urls && preg_match('/\b(zoek(?:t|en)?(?: even)?(?: (?:op|naar|uit))?|opzoeken|google|googel|search|nieuws(?:berichten)?|nieuwtjes|actuele?|laatste (?:nieuws|ontwikkelingen|berichten|stand)|nieuwste|vandaag in het nieuws|koers van|aandelenkoers|prijs van|wat kost|weerbericht|weersverwachting|het weer|weer (?:in|van|morgen|vandaag)|wie is (?:de|het) (?:huidige|nieuwe)|wie wint|wie heeft gewonnen|uitslag|eindstand|verkiezingen?)\b/iu', $t)) {
            $q = preg_replace('/^\s*(?:zoek(?:t|en)?(?: even)?(?: (?:op|naar|uit))?|kun je|kan je|wil je|kunt u|zou je)\s+(?:even\s+)?(?:(?:voor mij|me|mij)\s+)?(?:iets\s+)?(?:zoeken\s+)?(?:op|naar)?\s*/iu', '', trim($text)) ?? $text;
            $q = trim(preg_replace('/\s+/u', ' ', $q) ?? $q, " \t\n?!.");
            if (mb_strlen($q) < 2) $q = trim($text);
            $plan[] = ['web_search', ['query' => mb_substr($q, 0, 200), 'max_results' => 5, 'language' => 'nl']];
        }

        if (preg_match('/\b(eerder|vorige keer|vorig gesprek|vorige gesprekken|eerdere gesprekken?|laatst (?:zei|vertelde|noemde)|vroeger|wat zei ik|heb ik (?:je )?(?:verteld|gezegd|gevraagd)|onthouden|onthoud|herinner|weet je nog|mijn voorkeuren?|ik heb (?:eerder|al) (?:gezegd|verteld))\b/iu', $t)) {
            $plan[] = ['memory_search', ['query' => mb_substr(trim($text), 0, 200), 'limit' => 5]];
        }

        if (preg_match('/\b(mijn (?:recente |eerdere )?gesprekken|lijst (?:van )?(?:mijn )?gesprekken|recente gesprekken|hoeveel gesprekken|aantal gesprekken|gespreksstatistiek)\b/iu', $t)) {
            $plan[] = preg_match('/\b(hoeveel|aantal|statistiek)\b/iu', $t) ? ['database_query', ['operation' => 'conversations.stats']] : ['database_query', ['operation' => 'conversations.list_recent', 'parameters' => ['limit' => 10]]];
        }

        if (preg_match('/\b(status|bereikbaar|online|offline|werkt|draait|draaien|storing|beschikbaar)\b/iu', $t) && preg_match('/\b(ai|lm studio|lmstudio|model|server|mini-?pc|systeem|qwen)\b/iu', $t)
            || preg_match('/\b(cpu|geheugengebruik|ram-?gebruik|processorgebruik)\b/iu', $t)) {
            $plan[] = ['get_system_status', ['detail' => preg_match('/\b(cpu|geheugen|ram|processor|volledig|details?)\b/iu', $t) ? 'full' : 'basic']];
        }
        return array_slice($plan, 0, 3);
    }

    /** Welke tools zijn relevant voor deze vraag (voor native tool calling: bied alleen deze aan). */
    public static function relevantTools(string $text): array
    {
        $names = array_map(static fn($p) => $p[0], self::plan($text));
        if (in_array('web_search', $names, true) && !in_array('fetch_page', $names, true)) $names[] = 'fetch_page';
        return array_values(array_unique($names));
    }
}

# AI-tools voor de chat (Qwen3 via LM Studio)

De zes tools draaien in PHP op de server (`chat-run.php` → `ai/Controller.php`). Het model voert zelf nooit iets uit:
alleen PHP zoekt, haalt pagina's op en leest gegevens. Een antwoord als "ik ga zoeken" telt niet als zoekactie.

## Wat is er aanwezig (bestaande architectuur)

De app is een statische chatpagina (`index.html`) met twee PHP-bestanden: `chat-config.php` (gedeelde instellingen met
beheerderswachtwoord) en `chat-run.php` (antwoord op de server laten doorlopen). Er is **geen** database, **geen**
accountsysteem en **geen** takenmodule. De tools zijn daarop afgestemd:

- Opslag: SQLite in `storage/ai.sqlite` (migraties in `ai/migrations/`, automatisch toegepast, FTS5 als beschikbaar).
- Identiteit: een anoniem, willekeurig token dat de browser bewaart (`localStorage` → `lai.cid`); de server gebruikt een hash
  ervan als `user_id`. Het model bepaalt dit nooit. **Let op:** dit is geen echte inlog. Wie het token kent, kan bij dat
  geheugen; zodra er accounts zijn, vervang je `$userId` in `chat-run.php` door de echte gebruiker/tenant.
- PHP 8.1 of hoger, extensies `curl`, `dom`, `mbstring`, `pdo_sqlite`.

## De tools

| Tool | Wat | Opmerkingen |
|---|---|---|
| `get_datetime` | Serverklok (`DateTimeImmutable`, IANA-tijdzone, gevalideerd). | De actuele tijd staat daarnaast **altijd** in de system-prompt. |
| `web_search` | Echte zoekopdracht via Brave Search of SearXNG. | Timeout, 1 retry, rate limit per gebruiker, ontdubbeld, alleen http(s)-URL's, geen verzonnen publicatiedatum. Zonder provider/sleutel: duidelijke melding, geen resultaten. |
| `fetch_page` | Leest een openbare pagina als platte tekst. | Alleen URL's uit zoekresultaten of uit het bericht van de gebruiker. SSRF-bescherming, zie hieronder. |
| `memory_search` | Zoekt in eerdere gesprekken van dezelfde gebruiker (FTS5, anders LIKE). | Het lopende gesprek wordt overgeslagen; resultaten zijn herinneringen, geen actuele feiten. |
| `database_query` | Allowlist van leesoperaties: `conversations.list_recent`, `conversations.stats`. | Geen SQL van het model. `tasks.list_open` uit de specificatie is **niet** gemaakt: de app heeft geen takentabel. Nieuwe operaties voeg je toe in `Tools::DB_OPERATIONS` en een tak in `tool_database_query`. |
| `get_system_status` | Echte healthcheck van LM Studio (`GET /v1/models`, plus `/api/v0/models` voor geladen modellen). | Gewone gebruikers: alleen "bereikbaar". Beheerders (wachtwoord meegestuurd): modellen + host-statistieken. CPU/RAM alleen via een statusagent (zie onder), anders `null`. |

### SSRF-bescherming van `fetch_page` (`Ssrf.php`, `SafeFetch.php`)

Alleen `https` op poort 443, geen gebruikersnaam in de URL, geen interne hostnamen (`localhost`, `.local`, `.internal`, `.lan`, namen zonder punt).
De hostnaam wordt zelf opgelost; **alle** adressen moeten publiek zijn (geen 127/8, RFC1918, 100.64/10, 169.254/16 incl. metadata,
IPv6 ::1, fc00::/7, fe80::/10, IPv4 in IPv6, NAT64, 6to4, enz.). De verbinding wordt aan dat IP vastgepind (`CURLOPT_RESOLVE`,
nooit via een omgevings-proxy) en het daadwerkelijk verbonden IP wordt achteraf nogmaals gecontroleerd. Redirects worden handmatig gevolgd
(max. 3) en elke stap wordt opnieuw gevalideerd. Beperkte downloadgrootte (1,5 MB), tijd en Content-Type (alleen HTML en platte tekst).
HTML wordt veilig omgezet (zonder scripts, stijlen en verborgen elementen).

### Prompt-injectie

Toolresultaten komen als `<tool_result>`-data in het laatste gebruikersbericht (router) of als tool-bericht (native), met de uitdrukkelijke regel in de
system-prompt dat dit data is en nooit instructie. Het model krijgt geen schrijf- of beheerrechten: tools zijn read-only, argumenten en rechten worden in PHP
gevalideerd, `fetch_page` mag alleen eerder gevonden URL's lezen, en de router beslist nooit op basis van webinhoud.
Een klein model kan zich nog steeds laten misleiden in de **inhoud** van zijn antwoord; er is geen handeling die daardoor wordt uitgevoerd.

## Toolmodus: router of native

`AI_TOOL_MODE` (of de instelling "AI-tools" in de beheerdersinstellingen):

- `router` (standaard): PHP kiest op basis van de vraag (Nederlandse intentherkenning in `Router.php`) welke tools lopen en geeft de echte
  uitkomsten als context mee. Werkt ook als tool calling van het model onbetrouwbaar is. Voor Qwen3-1.7B is dit de aanbevolen stand.
- `native`: het model mag via de OpenAI-tools-API zelf tools kiezen (max. `AI_MAX_TOOL_ROUNDS`, standaard 3); er worden alleen relevante tools
  aangeboden. Tekst in toolrondes wordt niet getoond. Ook `<tool_call>…</tool_call>`-tekst (Qwen zonder serverside parsing) wordt herkend.
- `off`: geen tools (de serverklok staat wel in de prompt).

> **Niet geverifieerd met een echt model.** Of jouw LM Studio-versie en de geladen Qwen3-1.7B-variant native tool calling stabiel
> ondersteunen, is hier niet getest (geen toegang tot jouw LM Studio). De native stand is getest tegen een nagebootste server die
> `tool_calls` streamt. Test `native` zelf met echte vragen voordat je hem standaard maakt.

## Configuratie

Omgevingsvariabelen hebben voorrang. Hosts zonder omgevingsvariabelen: maak `storage/ai-env.php` (wordt niet gedeployed of overschreven):

```php
<?php return [
  'SEARCH_PROVIDER' => 'brave',              // brave | searxng
  'BRAVE_SEARCH_API_KEY' => '...',           // geheim: nooit in de repository of frontend
  // 'SEARXNG_BASE_URL' => 'https://jouw-searxng',
  // 'LM_STUDIO_MODEL_ID' => 'exacte-id-uit-/v1/models',
];
```

| Variabele | Standaard | Betekenis |
|---|---|---|
| `LM_STUDIO_BASE_URL` | gedeelde instelling "Base URL" | Moet vanaf de **webserver** bereikbaar zijn (tunnel), eindigend op `/v1`. |
| `LM_STUDIO_MODEL_ID` | gedeelde instelling "Model", anders eerste niet-embedding-ID uit `/v1/models` | Exacte model-ID. |
| `AI_TIMEZONE` | `Europe/Amsterdam` | Tijdzone van de serverklok. |
| `SEARCH_PROVIDER`, `BRAVE_SEARCH_API_KEY`, `SEARXNG_BASE_URL` | – | Zonder deze: `web_search` meldt eerlijk dat zoeken niet beschikbaar is. |
| `AI_TOOL_MODE` | `router` | `router`, `native` of `off`. |
| `AI_MAX_TOOL_ROUNDS` | 3 | Alleen native. |
| `AI_WEB_TIMEOUT_SECONDS` | 8 | Timeout voor zoeken en pagina's. |
| `AI_TOTAL_SECONDS` | 45 | Totaal budget voor toolrondes per verzoek. |
| `AI_CONTEXT_CHARS` | 7000 | Maximale omvang van toolresultaten in de prompt (compact houden voor een venster van 8192 tokens). |
| `AI_RATE_SEARCH_PER_MIN` | 10 | Zoek- en pagina-aanvragen per gebruiker per minuut. |
| `AI_RETENTION_DAYS` | 90 | Bewaartermijn van opgeslagen gesprekken. |
| `AI_FETCH_ALLOW_HTTP` | uit | Alleen aanzetten met een goede reden. |
| `STATUS_AGENT_URL`, `STATUS_AGENT_TOKEN` | – | Zie hieronder. |

### Statusagent (CPU/RAM)

Een externe PHP-host kan de mini-pc niet uitlezen. Draai op de mini-pc een kleine, geauthenticeerde agent die op `GET` met
`Authorization: Bearer <token>` dit JSON teruggeeft: `{"cpu_usage_percent":12.5,"memory_used_mb":4096,"memory_total_mb":16384}`.
Zonder agent geeft `get_system_status` voor deze waarden `null` (nooit geschatte cijfers). De agent zelf is **niet** meegeleverd.

### LM Studio-endpoint beveiligen

Een publiek bereikbare LM Studio-tunnel moet authenticatie hebben (LM Studio: "Require Authentication"; de sleutel stel je als beheerder
in en blijft op de server) plus rate limiting en logging aan de tunnelkant. `chat-run.php` begrenst alleen per IP.

## Opslag, privacy en verwijdering

Met "Gesprekken op de server bewaren" (standaard aan, uit te zetten door de beheerder) worden vragen en antwoorden (tekst, max. 8000 tekens) opgeslagen met
gebruiker-hash, gesprek, rol en tijd. Waarschijnlijke geheimen (wachtwoorden, API-sleutels, tokens, private keys) worden eerst vervangen door
`[geheim verwijderd]`. Bezoekers wissen een gesprek of al hun serverdata zelf in de app; alles ouder dan `AI_RETENTION_DAYS` wordt automatisch gewist.

Het log (`storage/ai-log.jsonl`) bevat alleen toolnaam, duur, succes, foutcode en provider: geen vraaginhoud, sleutels of interne adressen.

## Tests

`php tests/run.php` start een lokale testserver (nagebootste LM Studio, zoekdiensten en website) en draait de tests voor happy paths, fouten,
autorisatie en SSRF. Er wordt niets via internet opgehaald. `tests/` wordt niet gedeployed.

Niet gedekt: een echte Brave-/SearXNG-aanroep, een echte LM Studio met Qwen3-1.7B en een live `fetch_page` naar een openbare site (zonder internet in de testomgeving).
Voer die handmatig uit na de configuratie.

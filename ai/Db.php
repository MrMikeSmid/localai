<?php
declare(strict_types=1);
namespace Rv\Ai;
defined('RV_AI') or exit;

/**
 * SQLite-opslag voor gesprekken, geheugen en rate limits.
 * Elke query filtert op user_id dat de PHP-backend bepaalt; het model kiest nooit van wie gelezen wordt.
 */
final class Db
{
    private \PDO $pdo;
    private bool $fts;

    private const STOP = ['de','het','een','en','of','dat','die','dit','deze','van','voor','met','op','in','aan','te','is','zijn','was','waren','ik','je','jij','jou','mijn','me','we','wij','ze','hij','zij','het','ook','maar','als','dan','nog','wel','niet','geen','wat','wie','hoe','waar','wanneer','er','om','bij','naar','uit','over','tot','heb','heeft','hebt','had','kan','kun','zei','zeg','eerder','vorige','keer','weet','nog','hebben','worden','wordt','moet','mag','mogelijk','graag','even'];

    public function __construct(string $path, ?bool $useFts = null)
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) throw new \RuntimeException('Opslagmap kan niet worden aangemaakt.');
        $this->pdo = new \PDO('sqlite:' . $path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $this->pdo->exec('PRAGMA journal_mode=WAL');
        $this->pdo->exec('PRAGMA busy_timeout=3000');
        $this->migrate($useFts);
    }

    /** Past de migraties toe (idempotent). */
    private function migrate(?bool $useFts): void
    {
        $this->pdo->exec((string)file_get_contents(__DIR__ . '/migrations/001_init.sql'));
        $this->fts = false;
        if ($useFts !== false) {
            try {
                $this->pdo->exec((string)file_get_contents(__DIR__ . '/migrations/002_fts.sql'));
                $this->fts = true;
            } catch (\Throwable $e) {
                $this->fts = false; // geen FTS5: val terug op LIKE-zoeken
            }
        }
    }

    public function hasFts(): bool { return $this->fts; }

    /** Haalt waarschijnlijke geheimen weg voordat tekst wordt opgeslagen. */
    public static function redact(string $t): string
    {
        $p = [
            '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?(?:-----END [A-Z ]*PRIVATE KEY-----|$)/s',
            '/\b(?:sk|pk|rk)-[A-Za-z0-9_\-]{16,}\b/',
            '/\bBearer\s+[A-Za-z0-9._\-]{16,}/i',
            '/\b(?:ghp|gho|ghu|ghs|github_pat)_[A-Za-z0-9_]{20,}\b/',
            '/\bxox[baprs]-[A-Za-z0-9\-]{10,}\b/',
            '/\bAKIA[0-9A-Z]{16}\b/',
            '/\bAIza[0-9A-Za-z_\-]{30,}\b/',
            '/\b(?:wachtwoord|password|passwd|pwd|api[_\- ]?key|apikey|token|secret|geheim)\s*(?:is|=|:)\s*\S+/iu',
            '/\b(?=[A-Za-z0-9+\/_]*\d)(?=[A-Za-z0-9+\/_]*[A-Za-z])[A-Za-z0-9+\/_]{40,}={0,2}/',
        ];
        return preg_replace($p, '[geheim verwijderd]', $t) ?? $t;
    }

    public static function validId(string $id): bool { return (bool)preg_match('/^[A-Za-z0-9_\-]{4,64}$/', $id); }

    public function ensureConversation(string $userId, string $convId, string $title = ''): void
    {
        $now = time();
        $st = $this->pdo->prepare('INSERT INTO conversations (id,user_id,title,created_at,updated_at) VALUES (?,?,?,?,?) ON CONFLICT(user_id,id) DO UPDATE SET updated_at=excluded.updated_at');
        $st->execute([$convId, $userId, mb_substr(self::redact($title), 0, 80), $now, $now]);
    }

    public function saveMessage(string $userId, string $convId, string $role, string $text, ?int $ts = null): ?int
    {
        $text = trim(self::redact($text));
        if ($text === '' || !in_array($role, ['user', 'assistant'], true)) return null;
        $text = mb_substr($text, 0, 8000);
        $this->ensureConversation($userId, $convId, $role === 'user' ? $text : '');
        $st = $this->pdo->prepare('INSERT INTO messages (user_id,conversation_id,role,content,created_at) VALUES (?,?,?,?,?)');
        $st->execute([$userId, $convId, $role, $text, $ts ?? time()]);
        return (int)$this->pdo->lastInsertId();
    }

    /** @return string[] */
    public static function tokens(string $q): array
    {
        $q = mb_strtolower($q);
        preg_match_all('/[\p{L}\p{N}]{3,}/u', $q, $m);
        $out = [];
        foreach ($m[0] as $w) if (!in_array($w, self::STOP, true)) $out[$w] = true;
        return array_slice(array_keys($out), 0, 8);
    }

    /**
     * Zoekt in eerdere gesprekken van deze gebruiker (het lopende gesprek wordt overgeslagen).
     * @return array<int,array{memory_id:string,content:string,created_at:string,score:float,role:string}>
     */
    public function searchMemory(string $userId, string $query, int $limit = 5, ?string $excludeConv = null): array
    {
        $tokens = self::tokens($query);
        if (!$tokens) return [];
        $limit = max(1, min(10, $limit));
        $ex = $excludeConv ?? '';
        $rows = [];
        if ($this->fts) {
            $match = implode(' OR ', array_map(static fn($t) => '"' . str_replace('"', '""', $t) . '"', $tokens));
            $st = $this->pdo->prepare('SELECT m.id,m.role,m.content,m.created_at,bm25(messages_fts) AS r FROM messages_fts JOIN messages m ON m.id=messages_fts.rowid WHERE messages_fts MATCH ? AND m.user_id=? AND m.conversation_id<>? ORDER BY r LIMIT ?');
            $st->execute([$match, $userId, $ex, $limit * 4]);
            foreach ($st->fetchAll() as $r) {   // FTS bepaalt de volgorde; de score is het aandeel gevonden zoekwoorden
                $l = mb_strtolower($r['content']);
                $hit = 0;
                foreach ($tokens as $t) if (mb_strpos($l, $t) !== false) $hit++;
                $rows[] = $r + ['score' => round(max($hit, 1) / count($tokens), 2)];
            }
        } else {
            $where = implode(' OR ', array_fill(0, count($tokens), "content LIKE ? ESCAPE '\\'"));
            $args = [$userId, $ex];
            foreach ($tokens as $t) $args[] = '%' . addcslashes($t, '%_\\') . '%';
            $args[] = $limit * 20;
            $st = $this->pdo->prepare("SELECT id,role,content,created_at FROM messages WHERE user_id=? AND conversation_id<>? AND ($where) ORDER BY created_at DESC LIMIT ?");
            $st->execute($args);
            foreach ($st->fetchAll() as $r) {
                $l = mb_strtolower($r['content']);
                $hit = 0;
                foreach ($tokens as $t) if (mb_strpos($l, $t) !== false) $hit++;
                $rows[] = $r + ['score' => round($hit / count($tokens), 2)];
            }
            usort($rows, static fn($a, $b) => $b['score'] <=> $a['score'] ?: $b['created_at'] <=> $a['created_at']);
        }
        $out = []; $seen = [];
        foreach ($rows as $r) {
            $frag = self::fragment($r['content'], $tokens);
            $key = md5(mb_strtolower(preg_replace('/\s+/u', ' ', $frag) ?? $frag));
            if (isset($seen[$key]) || mb_strlen($frag) < 3) continue;   // dubbel of irrelevant
            $seen[$key] = true;
            $out[] = ['memory_id' => (string)$r['id'], 'content' => $frag, 'created_at' => gmdate('c', (int)$r['created_at']), 'score' => (float)$r['score'], 'role' => (string)$r['role']];
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    private static function fragment(string $text, array $tokens, int $span = 300): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if (mb_strlen($text) <= $span) return $text;
        $l = mb_strtolower($text); $pos = null;
        foreach ($tokens as $t) { $p = mb_strpos($l, $t); if ($p !== false && ($pos === null || $p < $pos)) $pos = $p; }
        $start = max(0, ($pos ?? 0) - 80);
        return ($start > 0 ? '…' : '') . trim(mb_substr($text, $start, $span)) . ($start + $span < mb_strlen($text) ? '…' : '');
    }

    /** @return array<int,array{id:string,title:string,updated_at:string,message_count:int}> */
    public function listConversations(string $userId, int $limit = 10): array
    {
        $st = $this->pdo->prepare('SELECT c.id,c.title,c.updated_at,(SELECT COUNT(*) FROM messages m WHERE m.user_id=c.user_id AND m.conversation_id=c.id) AS n FROM conversations c WHERE c.user_id=? ORDER BY c.updated_at DESC LIMIT ?');
        $st->execute([$userId, max(1, min(20, $limit))]);
        return array_map(static fn($r) => ['id' => (string)$r['id'], 'title' => (string)$r['title'], 'updated_at' => gmdate('c', (int)$r['updated_at']), 'message_count' => (int)$r['n']], $st->fetchAll());
    }

    /** @return array{conversations:int,messages:int,first_at:?string,last_at:?string} */
    public function stats(string $userId): array
    {
        $a = $this->pdo->prepare('SELECT COUNT(*) FROM conversations WHERE user_id=?'); $a->execute([$userId]);
        $b = $this->pdo->prepare('SELECT COUNT(*) AS n, MIN(created_at) AS f, MAX(created_at) AS l FROM messages WHERE user_id=?'); $b->execute([$userId]);
        $r = $b->fetch();
        return ['conversations' => (int)$a->fetchColumn(), 'messages' => (int)$r['n'], 'first_at' => $r['f'] ? gmdate('c', (int)$r['f']) : null, 'last_at' => $r['l'] ? gmdate('c', (int)$r['l']) : null];
    }

    public function deleteConversation(string $userId, string $convId): void
    {
        $this->pdo->prepare('DELETE FROM messages WHERE user_id=? AND conversation_id=?')->execute([$userId, $convId]);
        $this->pdo->prepare('DELETE FROM conversations WHERE user_id=? AND id=?')->execute([$userId, $convId]);
    }

    public function forget(string $userId): void
    {
        $this->pdo->prepare('DELETE FROM messages WHERE user_id=?')->execute([$userId]);
        $this->pdo->prepare('DELETE FROM conversations WHERE user_id=?')->execute([$userId]);
    }

    /** Retentie: verwijdert berichten en gesprekken ouder dan $days dagen. */
    public function purgeOlderThan(int $days): int
    {
        $cut = time() - max(1, $days) * 86400;
        $st = $this->pdo->prepare('DELETE FROM messages WHERE created_at<?'); $st->execute([$cut]);
        $n = $st->rowCount();
        $this->pdo->prepare('DELETE FROM conversations WHERE updated_at<?')->execute([$cut]);
        $this->pdo->prepare('DELETE FROM rate_limits WHERE ts<?')->execute([time() - 3600]);
        return $n;
    }

    /** True als de actie is toegestaan (en wordt geteld); false bij overschrijding van de limiet. */
    public function rateHit(string $key, int $limit, int $windowSeconds = 60): bool
    {
        $now = time();
        $this->pdo->prepare('DELETE FROM rate_limits WHERE k=? AND ts<?')->execute([$key, $now - $windowSeconds]);
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM rate_limits WHERE k=?'); $st->execute([$key]);
        if ((int)$st->fetchColumn() >= $limit) return false;
        $this->pdo->prepare('INSERT INTO rate_limits (k,ts) VALUES (?,?)')->execute([$key, $now]);
        return true;
    }
}

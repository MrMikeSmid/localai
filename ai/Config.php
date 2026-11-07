<?php
declare(strict_types=1);
namespace Rv\Ai;
defined('RV_AI') or exit;

/**
 * Configuratie: omgevingsvariabelen hebben voorrang, daarna storage/ai-env.php
 * (een PHP-bestand dat een array teruggeeft, voor hosts zonder omgevingsvariabelen),
 * daarna de gedeelde instellingen uit chat-config.php, daarna de standaardwaarde.
 * Geheimen (API-sleutels) komen nooit in de frontend of in de repository terecht.
 */
final class Config
{
    /** @var array<string,string> */
    private array $file = [];
    /** @var array<string,mixed> */
    private array $shared;
    /** @var array<string,string> */
    private array $overrides;

    /** Gedeelde-instellingsnaam per configuratiesleutel. */
    private const SHARED_MAP = [
        'LM_STUDIO_BASE_URL' => 'baseUrl',
        'LM_STUDIO_MODEL_ID' => 'model',
        'AI_TOOL_MODE' => 'toolMode',
    ];

    public function __construct(string $storageDir, array $shared = [], array $overrides = [])
    {
        $this->shared = $shared;
        $this->overrides = array_map('strval', $overrides);
        $f = $storageDir . '/ai-env.php';
        if (is_file($f)) {
            $a = (static function () use ($f) { return include $f; })();
            if (is_array($a)) foreach ($a as $k => $v) if (is_scalar($v)) $this->file[(string)$k] = (string)$v;
        }
    }

    public function get(string $key, ?string $default = null): ?string
    {
        if (isset($this->overrides[$key])) return $this->overrides[$key];
        $v = getenv($key);
        if ($v !== false && $v !== '') return $v;
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') return (string)$_ENV[$key];
        if (isset($this->file[$key]) && $this->file[$key] !== '') return $this->file[$key];
        $s = self::SHARED_MAP[$key] ?? null;
        if ($s !== null && isset($this->shared[$s]) && $this->shared[$s] !== '') return (string)$this->shared[$s];
        return $default;
    }

    public function int(string $key, int $default, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): int
    {
        $v = $this->get($key);
        $n = ($v !== null && is_numeric($v)) ? (int)$v : $default;
        return max($min, min($max, $n));
    }

    public function bool(string $key, bool $default): bool
    {
        $v = $this->get($key);
        if ($v === null) return $default;
        return in_array(strtolower($v), ['1', 'true', 'yes', 'ja', 'on'], true);
    }

    /** Gedeelde-instelling (uit chat-config.php) die geen environment-equivalent heeft. */
    public function shared(string $key, $default = null)
    {
        return array_key_exists($key, $this->shared) ? $this->shared[$key] : $default;
    }
}

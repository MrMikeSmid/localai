<?php
declare(strict_types=1);
namespace Rv\Ai;
defined('RV_AI') or exit;

/** Eenvoudig JSON-lines-log. Bevat alleen toolnaam, duur, status en foutcode: geen inhoud, sleutels of adressen. */
final class Log
{
    private string $file;

    public function __construct(string $storageDir)
    {
        $this->file = $storageDir . '/ai-log.jsonl';
    }

    public function tool(string $rid, string $tool, int $ms, bool $ok, ?string $code = null, ?string $source = null): void
    {
        $this->write(['rid' => $rid, 'tool' => $tool, 'ms' => $ms, 'ok' => $ok, 'code' => $code, 'source' => $source]);
    }

    public function event(string $rid, string $event, array $extra = []): void
    {
        $this->write(['rid' => $rid, 'event' => $event] + $extra);
    }

    private function write(array $row): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) return;
        if (is_file($this->file) && @filesize($this->file) > 1048576) @rename($this->file, $this->file . '.1');
        @file_put_contents($this->file, json_encode(['ts' => gmdate('c')] + $row, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    }
}

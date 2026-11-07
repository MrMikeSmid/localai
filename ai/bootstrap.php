<?php
/**
 * Start de AI-bibliotheek: autoloader en gedeelde constanten.
 * Alle bestanden in deze map zijn alleen bedoeld om door chat-run.php te worden geladen.
 */
declare(strict_types=1);

if (!defined('RV_AI')) {
    define('RV_AI', true);
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Rv\\Ai\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) require $file;
});

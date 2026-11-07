<?php
declare(strict_types=1);
namespace Rv\Ai;
defined('RV_AI') or exit;

/** Verwachte, veilig te tonen toolfout (code is machine-leesbaar, message is voor het model en de gebruiker). */
final class ToolError extends \RuntimeException
{
    public string $errorCode;

    public function __construct(string $errorCode, string $message)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
    }
}

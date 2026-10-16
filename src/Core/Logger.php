<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/** Writes one line per event to storage/logs/api-YYYY-MM-DD.log. Never throws. */
final class Logger
{
    public function __construct(private readonly string $directory)
    {
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->write('WARNING', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    /** @param array<string, mixed> $context */
    private function write(string $level, string $message, array $context): void
    {
        try {
            if (!is_dir($this->directory)) {
                mkdir($this->directory, 0775, true);
            }

            $line = sprintf(
                "[%s] %s: %s%s\n",
                gmdate('Y-m-d\TH:i:s\Z'),
                $level,
                $message,
                $context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
            );
            file_put_contents($this->directory . '/api-' . gmdate('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            error_log("[pkapparel-api] {$level}: {$message}");
        }
    }
}

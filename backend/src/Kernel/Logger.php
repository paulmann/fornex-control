<?php

/**
 * Журнал приложения: файл с простой ротацией по размеру.
 *
 * Секреты в журнал не попадают: значения ключей и паролей в сообщения не пишем.
 *
 * @package FornexPanel
 */

declare(strict_types=1);

namespace FornexPanel\Kernel;

final class Logger
{
    public function __construct(
        private readonly string $file,
        private readonly string $level = 'info',
        private readonly int $rotateBytes = 5_242_880,
        private readonly int $keepFiles = 5,
    ) {
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        if ($this->level === 'debug') {
            $this->write('debug', $message, $context);
        }
    }

    /** @param array<string, mixed> $context */
    private function write(string $level, string $message, array $context): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        $this->rotate();

        $line = sprintf(
            "%s %-7s %s%s\n",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            $context === [] ? '' : ' ' . \FornexPanel\Support\Json::encode($context),
        );

        @file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX);
    }

    private function rotate(): void
    {
        if (!is_file($this->file)) {
            return;
        }

        $size = @filesize($this->file);
        if ($size === false || $size < $this->rotateBytes) {
            return;
        }

        for ($index = $this->keepFiles - 1; $index >= 1; $index--) {
            $from = $this->file . '.' . $index;
            $to = $this->file . '.' . ($index + 1);

            if (is_file($from)) {
                @rename($from, $to);
            }
        }

        @rename($this->file, $this->file . '.1');
    }
}

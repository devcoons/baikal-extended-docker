<?php

declare(strict_types=1);

namespace BaikalExt;

/**
 * Minimal leveled logger writing to STDOUT/STDERR with timestamps so output is
 * useful both interactively and via `docker logs` when run from cron.
 */
final class Logger
{
    public function __construct(
        private bool $verbose = false,
        private bool $quiet = false,
    ) {
    }

    public function debug(string $message): void
    {
        if ($this->verbose && !$this->quiet) {
            $this->write(STDOUT, 'DEBUG', $message);
        }
    }

    public function info(string $message): void
    {
        if (!$this->quiet) {
            $this->write(STDOUT, 'INFO', $message);
        }
    }

    public function warning(string $message): void
    {
        $this->write(STDERR, 'WARN', $message);
    }

    public function error(string $message): void
    {
        $this->write(STDERR, 'ERROR', $message);
    }

    /** @param resource $stream */
    private function write($stream, string $level, string $message): void
    {
        fwrite($stream, sprintf('[%s] %-5s %s%s', date('Y-m-d H:i:s'), $level, $message, PHP_EOL));
    }
}

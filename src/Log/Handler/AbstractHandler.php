<?php
declare(strict_types=1);

namespace Zara\Log\Handler;

use Zara\Log\LogRecord;

abstract class AbstractHandler
{
    public function __construct(
        protected int $minLevel = 0
    ) {}

    /**
     * Checks whether the handler should process this log level.
     */
    public function isHandling(int $levelCode): bool
    {
        return $levelCode >= $this->minLevel;
    }

    /**
     * Main entry point for the handler.
     */
    public function handle(LogRecord $record): void
    {
        if (!$this->isHandling($record->levelCode)) {
            return;
        }

        $this->write($this->format($record));
    }

    /**
     * Method to be implemented by concrete handlers (File, Stream, etc.).
     */
    abstract protected function write(string $formatted): void;

    /**
     * Formats the log record into a default string representation.
     */
    protected function format(LogRecord $record): string
    {
        $context = !empty($record->context) 
            ? ' ' . json_encode($record->context, JSON_UNESCAPED_UNICODE) 
            : '';

        return sprintf(
            "[%s] %s: %s%s\n",
            date('Y-m-d H:i:s'),
            strtoupper($record->level),
            $record->message,
            $context
        );
    }
}
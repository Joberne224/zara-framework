<?php
declare(strict_types=1);

namespace Zara\Log;

use DateTimeImmutable;

/**
 * Immutable Data Transfer Object representing a single log record.
 */
final readonly class LogRecord
{
    public DateTimeImmutable $datetime;

    /**
     * @param string $level PSR-3 log level name (e.g., 'info', 'error')
     * @param int $levelCode Integer level code for fast threshold comparison
     * @param string $message Log message
     * @param array<string, mixed> $context Contextual data array
     * @param DateTimeImmutable|null $datetime Time of the event creation
     */
    public function __construct(
        public string $level,
        public int $levelCode,
        public string $message,
        public array $context = [],
        ?DateTimeImmutable $datetime = null,
    ) {
        $this->datetime = $datetime ?? new DateTimeImmutable();
    }
}
<?php
declare(strict_types=1);

namespace Zara\Log;

use Psr\Log\AbstractLogger;
use Zara\Log\Handler\AbstractHandler;

final class Logger extends AbstractLogger
{
    /** @var list<AbstractHandler> */
    private array $handlers = [];

    public function addHandler(AbstractHandler $handler): self
    {
        $this->handlers[] = $handler;
        return $this;
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $levelStr = (string) $level;
        $record = new LogRecord(
            level: $levelStr,
            levelCode: Level::toCode($levelStr),
            message: (string) $message,
            context: $context
        );

        foreach ($this->handlers as $handler) {
            $handler->handle($record);
        }
    }
}
<?php

declare(strict_types=1);

namespace Zara\Core\Debug\Profiler;

use Monolog\Handler\AbstractHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Zara\Core\Config\Env;

/**
 * Monolog handler bridging log entries into Profiler::addLog(), so they show
 * up in the debugbar's LogPanel.
 *
 * Extends Monolog\Handler\AbstractHandler (not the more basic Handler class,
 * which has no level/bubble concept and leaves isHandling() unimplemented) —
 * AbstractHandler is what actually provides the $level/$bubble constructor
 * and a working isHandling().
 *
 * bootstrap.php registers a raw Monolog\Logger as the container's official
 * LoggerInterface and pushes this handler onto that instance, so this is the
 * only log bridge in the profiler.
 *
 * Should only be attached in dev/debug — never in production.
 */
final class MonologProfilerHandler extends AbstractHandler
{
    public function __construct(
        private readonly Profiler $profiler,
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    public function handle(LogRecord $record): bool
    {
        if (!Env::isProfilerEnabled() || !$this->isHandling($record)) {
            return false;
        }

        $this->profiler->addLog(
            $record->level->toPsrLogLevel(),
            $record->message,
            $record->context,
        );

        // Returning false lets the record keep bubbling to the other
        // handlers already pushed onto the logger (e.g. the file handler) —
        // this handler only observes, it never stops normal logging.
        return false;
    }
}

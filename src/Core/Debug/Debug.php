<?php
declare(strict_types=1);

namespace Zara\Core\Debug;

/**
 * Static facade for the debugging helpers (dump, dumpCompact, dumpDiff, dd).
 *
 * This facade is synchronized with ErrorHandler's $debug flag. During
 * ErrorHandler::register(), Debug::enable($this->debug, $this->editor) is called
 * as the final bootstrap step. No separate configuration is needed: when
 * HtmlDevRenderer is active, debugging helpers are enabled; when
 * HtmlProdRenderer is active, they silently do nothing.
 *
 * This protects production requests from accidental debug calls left in
 * committed code: they produce no output and do not interrupt the request.
 */
final class Debug
{
    private static bool $enabled = false;
    private static string $editor = 'vscode';

    /**
     * @var list<string> HTML dump output, buffered instead of echoed
     * immediately. dump() can be called anywhere in the request lifecycle —
     * including before the page's own <!DOCTYPE html>/<head> is sent (e.g.
     * from a bootstrap file). Echoing right away in that case would break
     * the browser's HTML parse: content received before the DOCTYPE forces
     * quirks mode and makes the browser open an implicit <head></head><body>
     * on the spot, orphaning the real <head> — including its stylesheet
     * <link> — once it finally arrives. Buffering and flushing everything
     * once at shutdown guarantees dump() output always lands after the real
     * document has already streamed, regardless of call site.
     */
    private static array $buffer = [];
    private static bool $shutdownRegistered = false;

    /**
     * Enable or disable all debugging helpers globally.
     * ErrorHandler::register() calls this automatically, so applications do
     * not need to call it from their normal bootstrap code.
     */
    public static function enable(bool $enabled = true, string $editor = 'vscode'): void
    {
        self::$enabled = $enabled;
        self::$editor  = $editor;
    }

    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    /**
     * Display one or more values without stopping execution. When debug mode
     * is disabled, this is a complete no-op: no side effects, output, or
     * rendering cost.
     */
    public static function dump(mixed ...$vars): void
    {
        if (!self::$enabled) {
            return;
        }

        $caller = self::resolveCaller();

        if (PHP_SAPI === 'cli') {
            // No document structure to protect in a CLI process — safe,
            // and more useful, to echo synchronously as each dump() runs.
            foreach ($vars as $var) {
                echo VarDumper::toCli($var, $caller);
            }
            return;
        }

        foreach ($vars as $var) {
            self::$buffer[] = VarDumper::toHtml($var, $caller, self::$editor);
        }

        self::registerShutdownFlush();
    }

    /**
     * Display concise, single-line summaries without changing the behavior of
     * dump(). The same debug-mode gate and HTML shutdown buffer are used.
     */
    public static function dumpCompact(mixed ...$vars): void
    {
        if (!self::$enabled) {
            return;
        }

        if (PHP_SAPI === 'cli') {
            foreach ($vars as $var) {
                echo VarDumper::compactToCli($var);
            }
            return;
        }

        foreach ($vars as $var) {
            self::$buffer[] = VarDumper::compactToHtml($var);
        }

        self::registerShutdownFlush();
    }

    /** Compare two values and display their differences when debug is enabled. */
    public static function diff(mixed $before, mixed $after): void
    {
        if (!self::$enabled) {
            return;
        }

        if (PHP_SAPI === 'cli') {
            echo VarDumper::diffToCli($before, $after);
            return;
        }

        self::$buffer[] = VarDumper::diffToHtml($before, $after);
        self::registerShutdownFlush();
    }

    /**
     * Dump values and stop the script, but only when debug mode is enabled.
     * In production this is a complete no-op: it neither dumps nor exits, so
     * an accidental dd() left in deployed code cannot interrupt a request.
     *
     * exit() still lets PHP's registered shutdown functions run, so the
     * buffered dump above is flushed normally even here.
     */
    public static function dd(mixed ...$vars): void
    {
        if (!self::$enabled) {
            return;
        }

        self::dump(...$vars);
        exit(1);
    }

    /**
     * Registered once per request: PHP calls every shutdown function
     * exactly once, at script termination (including after exit()), which
     * is the one point guaranteed to come after all of the application's
     * own output has already been sent.
     */
    private static function registerShutdownFlush(): void
    {
        if (self::$shutdownRegistered) {
            return;
        }

        self::$shutdownRegistered = true;

        register_shutdown_function(static function (): void {
            foreach (self::$buffer as $html) {
                echo $html;
            }
            self::$buffer = [];
        });
    }

    /**
     * Walk the call stack to find the first frame outside this class; this is
     * the actual call site for a debugging helper.
     *
     * @return array{file: string, line: int}|null
     */
    private static function resolveCaller(): ?array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);

        foreach ($trace as $frame) {
            if (!isset($frame['file'])) {
                continue;
            }
            if (str_ends_with($frame['file'], 'Debug.php')) {
                continue;
            }
            if (str_ends_with($frame['file'], 'helpers.php')) {
                continue;
            }
            return ['file' => $frame['file'], 'line' => $frame['line'] ?? 0];
        }

        return null;
    }
}

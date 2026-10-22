<?php

declare(strict_types=1);

use Zara\Core\Debug\Debug;

/**
 * Global debugging functions, available throughout the application.
 *
 * IMPORTANT — this file must be registered in composer.json:
 *
 *   "autoload": {
 *       "files": ["src/Zara/Core/Debug/helpers.php"]
 *   }
 *
 * then run `composer dump-autoload`. Without this registration, dump()/dd()
 * are not loaded automatically (global functions do not follow the standard
 * PSR-4 autoloading rules).
 *
 * Actual availability depends on Debug::enable(), called by
 * ErrorHandler::register() — see Debug.php for details.
 */

if (!function_exists('dump')) {
    /**
     * Display one or more variables without stopping execution.
     * Silently does nothing when debug mode is disabled.
     */
    function dump(mixed ...$vars): void
    {
        Debug::dump(...$vars);
    }
}

if (!function_exists('dumpCompact')) {
    /**
     * Display concise, single-line summaries of one or more values.
     * This is a separate helper; dump() and dd() keep their existing output.
     */
    function dumpCompact(mixed ...$vars): void
    {
        Debug::dumpCompact(...$vars);
    }
}

if (!function_exists('dd')) {
    /**
     * Dump values and then stop the script. Does nothing at all (no dump, no
     * exit) when debug mode is disabled — safe to leave in code accidentally
     * deployed to production.
     */
    function dd(mixed ...$vars): void
    {
        Debug::dd(...$vars);
    }
}

if (!function_exists('dumpDiff')) {
    /** Display differences between two values only when debug mode is enabled. */
    function dumpDiff(mixed $before, mixed $after): void
    {
        Debug::diff($before, $after);
    }
}

<?php
declare(strict_types=1);

namespace Zara\Bootstrap;

use Zara\Container\Container;

/**
 * Backward-compatible static facade for the object-oriented Application API.
 * New applications can instantiate Zara\Bootstrap\Application directly.
 */
final class AppBootstrapper
{
    private static ?Container $container = null;

    /**
     * Bootstrap an application using the legacy static entry point.
     *
     * @param string $projectRoot Absolute or relative path to the project root.
     * @param string|null $configFile Optional application configuration file (YAML/PHP).
     * @param (callable(Container): void)|null $configure Optional container customization callback.
     */
    public static function boot(
        string $projectRoot,
        ?string $configFile = null,
        ?callable $configure = null,
    ): Container {
        $application = new Application($projectRoot);

        if ($configFile !== null) {
            $application->withConfigFile($configFile);
        }
        if ($configure !== null) {
            $application->configure($configure);
        }

        self::$container = $application->boot();
        return self::$container;
    }

    /** Return the container created by the most recent successful static boot(). */
    public static function getContainer(): Container
    {
        if (self::$container === null) {
            throw new \LogicException('AppBootstrapper must complete boot() before the container can be retrieved.');
        }

        return self::$container;
    }
}

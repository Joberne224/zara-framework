<?php
declare(strict_types=1);

namespace Zara\Core\Config;

/**
 * Source unique de vérité pour l'environnement et le mode debug.
 */
final class Env
{
    private static ?bool   $debugMode = null;
    private static ?string $environment = null;

    public static function init(?bool $debug = null, ?string $env = null): void
    {
        self::$environment = $env ?? $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'prod';
        $isDevelopment = self::isDevelopmentEnvironment(self::$environment);

        if ($debug !== null) {
            self::$debugMode = $debug && $isDevelopment;
            return;
        }

        $rawDebug = $_ENV['APP_DEBUG'] ?? $_SERVER['APP_DEBUG'] ?? null;
        if ($rawDebug !== null) {
            $configuredDebug = filter_var($rawDebug, FILTER_VALIDATE_BOOLEAN);
        } else {
            $configuredDebug = true;
        }

        self::$debugMode = $isDevelopment && $configuredDebug;
    }

    public static function isDebug(): bool
    {
        if (self::$debugMode === null) {
            self::init();
        }

        return self::$debugMode;
    }

    public static function getEnv(): string
    {
        if (self::$environment === null) {
            self::init();
        }

        return self::$environment;
    }

    /** Profiler data is collected only in an explicitly enabled dev environment. */
    public static function isProfilerEnabled(): bool
    {
        return self::isDebug();
    }

    private static function isDevelopmentEnvironment(string $environment): bool
    {
        return in_array(strtolower(trim($environment)), ['dev', 'development', 'local'], true);
    }
}

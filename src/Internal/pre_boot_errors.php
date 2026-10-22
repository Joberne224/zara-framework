<?php
declare(strict_types=1);

use Zara\Core\Error\ErrorHandler;

/**
 * This file enables the error handler globally.
 *
 * IMPORTANT — this file must be registered in composer.json:
 *
 *    "autoload": {
 *        "files": ["src/Internal/pre_boot_errors.php"]
 *    }
 */

if (!defined('ZARA_SYSTEM_ERRORS_LOADED')) {

    define('ZARA_SYSTEM_ERRORS_LOADED', true);

    /*
     * Reads a boolean from a string the way a .env file or the environment
     * spells it: "true", "1", "on", "yes" / "false", "0", "off", "no".
     * Returns null when the value is absent or not a recognizable boolean, so
     * the caller can fall back instead of guessing.
     */
    $parseBool = static function (mixed $value): ?bool {
        if (!is_string($value)) {
            return null;
        }

        return filter_var(trim($value), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    };

    /* Reads one .env value, including quotes, export prefixes and comments. */
    $readEnvValue = static function (string $content, string $name): ?string {
        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
            if (preg_match('/^\s*(?:export\s+)?' . preg_quote($name, '/') . '\s*=\s*(.*)$/', $line, $m) !== 1) {
                continue;
            }

            $raw = trim($m[1]);

            if ($raw !== '' && ($raw[0] === '"' || $raw[0] === "'")) {
                // Quoted: keep what is inside the quotes, ignore what follows.
                $closing = strpos($raw, $raw[0], 1);
                $raw = $closing === false ? substr($raw, 1) : substr($raw, 1, $closing - 1);
            } else {
                // Unquoted: an inline comment starts at " #".
                $raw = preg_replace('/\s+#.*$/', '', $raw) ?? $raw;
            }

            return $raw;
        }

        return null;
    };

    // Process environment takes precedence over .env, matching Dotenv::createImmutable().
    $preBootEnvironment = $_SERVER['APP_ENV'] ?? (getenv('APP_ENV') ?: null);
    $preBootRawDebug = $_SERVER['APP_DEBUG'] ?? (getenv('APP_DEBUG') ?: null);

    /*
     * Locating the project root WITHOUT assuming how deep this file sits.
     *
     * Why dirname(__DIR__, 3) is broken: it assumes this file is always exactly
     * 3 levels below the root. That is only true when this package is the root
     * project (local development). Once it is installed as a dependency it lives
     * in vendor/zara/src/Core/Internal/..., with extra levels in between, and
     * dirname(__DIR__, 3) then points inside vendor/zara/ (or elsewhere), never
     * at the real root.
     *
     * Solution: Composer\Autoload\ClassLoader is ALWAYS loaded once Composer's
     * autoloader has started (guaranteed here, since this file is itself loaded
     * through a Composer "autoload.files" entry). Its file ALWAYS lives at
     * {vendor-dir}/composer/ClassLoader.php, whatever the vendor-dir is called
     * and however deep this package is installed. Two dirname() calls therefore
     * climb to the vendor folder, and a third one to the real root of the
     * consuming project.
     */
    $rootDir = null;

    if (class_exists(\Composer\Autoload\ClassLoader::class)) {
        $classLoaderFile = (new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName();

        if ($classLoaderFile !== false) {
            $vendorDir = dirname($classLoaderFile, 2); // .../vendor
            $rootDir   = dirname($vendorDir);          // project root
        }
    }

    if ($rootDir !== null) {
        $file = $rootDir . '/.env';

        if (file_exists($file) && is_readable($file)) {

            $content = @file_get_contents($file);

            if ($content !== false) {
                $preBootEnvironment ??= $readEnvValue($content, 'APP_ENV');
                $preBootRawDebug ??= $readEnvValue($content, 'APP_DEBUG');
            }

        }
    }

    $environment = strtolower(trim((string) ($preBootEnvironment ?? 'prod')));
    $isDevelopment = in_array($environment, ['dev', 'development', 'local'], true);
    $configuredDebug = $preBootRawDebug === null
        ? true
        : ($parseBool((string) $preBootRawDebug) ?? false);
    $preBootDebug = $isDevelopment && $configuredDebug;

    ErrorHandler::create($preBootDebug)
    ->register();
}

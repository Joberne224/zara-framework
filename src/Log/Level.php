<?php
declare(strict_types=1);

namespace Zara\Log;

use InvalidArgumentException;
use Psr\Log\LogLevel;

/**
 * Defines integer severity codes for log levels and provides resolution utilities.
 */
final class Level
{
    public const DEBUG     = 100;
    public const INFO      = 200;
    public const NOTICE    = 250;
    public const WARNING   = 300;
    public const ERROR     = 400;
    public const CRITICAL  = 500;
    public const ALERT     = 550;
    public const EMERGENCY = 600;

    /**
     * Map of PSR-3 string level names to their numeric severity codes.
     *
     * @var array<string, int>
     */
    public const MAP = [
        LogLevel::DEBUG     => self::DEBUG,
        LogLevel::INFO      => self::INFO,
        LogLevel::NOTICE    => self::NOTICE,
        LogLevel::WARNING   => self::WARNING,
        LogLevel::ERROR     => self::ERROR,
        LogLevel::CRITICAL  => self::CRITICAL,
        LogLevel::ALERT     => self::ALERT,
        LogLevel::EMERGENCY => self::EMERGENCY,
    ];

    /**
     * Converts a PSR-3 level string to its corresponding integer code.
     *
     * @throws InvalidArgumentException If an unsupported level name is provided.
     */
    public static function toCode(string $level): int
    {
        $normalized = strtolower($level);

        return self::MAP[$normalized] ?? throw new InvalidArgumentException(
            sprintf('Undefined log level "%s". Accepted levels are: %s.', $level, implode(', ', array_keys(self::MAP)))
        );
    }

    /**
     * Checks whether the given string is a recognized PSR-3 log level.
     */
    public static function isValid(string $level): bool
    {
        return isset(self::MAP[strtolower($level)]);
    }
}
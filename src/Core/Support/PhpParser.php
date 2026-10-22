<?php

declare(strict_types=1);

namespace Zara\Core\Support;

use Zara\Core\Exceptions\ParseException;

final class PhpParser
{
    /**
     * Safely includes a PHP configuration file and returns its array data.
     *
     * @param string $file Absolute path to the PHP configuration file.
     * @return array
     * @throws ParseException If path contains wrappers, does not exist, or does not return an array.
     */
    public function parse(string $file): array
    {
        if (str_contains($file, '://')) {
            throw new ParseException("PHP Parser Error: Stream wrappers and remote paths are strictly forbidden.");
        }

        $realPath = realpath($file);

        if ($realPath === false || !is_file($realPath) || !is_readable($realPath)) {
            throw new ParseException(
                sprintf("PHP Parser Error: Configuration file '%s' does not exist or is not readable.", $file)
            );
        }

        $config = include $realPath;

        if (!is_array($config)) {
            throw new ParseException(
                sprintf("PHP Parser Error: Configuration file '%s' must return a PHP array.", $file)
            );
        }

        return $config;
    }
}
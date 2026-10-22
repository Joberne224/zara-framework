<?php

declare(strict_types=1);

namespace Zara\Core\Support;

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException as SymfonyYamlException;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Zara\Core\Exceptions\ParseException;

final class YamlParser
{
    /**
     * Safely parses a YAML configuration file into a PHP array.
     *
     * @param string $file Absolute path to the YAML file.
     * @return array
     * @throws ParseException If component is missing, path is invalid, or parsing fails.
     */
    public function parse(string $file): array
    {
        if (!class_exists(Yaml::class)) {
            throw new ParseException(
                "YAML Parser Error: To use YAML configuration, please run 'composer require symfony/yaml'."
            );
        }

        if (str_contains($file, '://')) {
            throw new ParseException("YAML Parser Error: Stream wrappers and remote paths are strictly forbidden.");
        }

        $realPath = realpath($file);

        if ($realPath === false || !is_file($realPath) || !is_readable($realPath)) {
            throw new ParseException(
                sprintf("YAML Parser Error: Configuration file '%s' does not exist or is not readable.", $file)
            );
        }

        try {
            $config = Yaml::parseFile($realPath, Yaml::PARSE_CUSTOM_TAGS);
        } catch (SymfonyYamlException $e) {
            throw new ParseException(
                sprintf("YAML Parser Error: Failed to parse file '%s': %s", $file, $e->getMessage()),
                (int) $e->getCode(),
                $e
            );
        }

        return is_array($config) ? $this->normalizeTaggedValues($config) : [];
    }

    private function normalizeTaggedValues(array $data): array
    {
        foreach ($data as $k => $v) {
            if ($v instanceof TaggedValue) {
                $data[$k] = '!' . $v->getTag() . ($v->getValue() !== null ? ':' . $v->getValue() : '');
            } elseif (is_array($v)) {
                $data[$k] = $this->normalizeTaggedValues($v);
            }
        }

        return $data;
    }
}
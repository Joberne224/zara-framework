<?php

declare(strict_types=1);

namespace Zara\Core\Routing\Yaml;

use InvalidArgumentException;

/**
 * Validates the structure and schema of YAML route and group definitions.
 *
 * Responsibility: Ensure that raw data arrays parsed from YAML strictly conform
 * to the routing configuration schema before registration.
 */
final class YamlRouteValidator
{
    private const SUPPORTED_METHODS = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS', 'ANY'];
    private const REQUIRED_ROUTE_KEYS = ['path', 'method', 'callable'];

    /**
     * Validates a single route definition array.
     *
     * @param mixed $definition Raw route definition data.
     * @param string $context Context string for detailed error reporting.
     * @throws InvalidArgumentException If the definition structure or any field is invalid.
     */
    public function validateRoute(mixed $definition, string $context): void
    {
        $this->assertIsArray($definition, $context);
        $this->assertRequiredKeys($definition, self::REQUIRED_ROUTE_KEYS, $context);

        $method = strtoupper($this->assertString($definition['method'], 'method', $context));
        $this->assertSupportedMethod($method, $context);

        $this->assertString($definition['path'], 'path', $context);
        $this->validateCallable($definition['callable'], $context);

        if (isset($definition['namespace'])) {
            $this->assertString($definition['namespace'], 'namespace', $context);
        }

        if (!empty($definition['middlewares'])) {
            $this->validateMiddlewares($definition['middlewares'], $context);
        }

        if (!empty($definition['constraints'])) {
            $this->validateConstraints($definition['constraints'], $context);
        }
    }

    /**
     * Validates a route group definition array.
     *
     * @param mixed $definition Raw group definition data.
     * @param string $context Context string for detailed error reporting.
     * @throws InvalidArgumentException If group definition or prefix is invalid.
     */
    public function validateGroup(mixed $definition, string $context): void
    {
        $this->assertIsArray($definition, $context);

        if (!isset($definition['prefix'])) {
            throw new InvalidArgumentException(
                sprintf("A route group must define a 'prefix' key in %s.", $context)
            );
        }

        $this->assertString($definition['prefix'], 'prefix', $context);

        if (isset($definition['namespace'])) {
            $this->assertString($definition['namespace'], 'namespace', $context);
        }

        if (isset($definition['middlewares'])) {
            $this->validateMiddlewares($definition['middlewares'], $context);
        }
    }

    /**
     * Validates callable declaration formats (string or 2-element array).
     */
    public function validateCallable(mixed $value, string $context): void
    {
        if (is_string($value)) {
            return;
        }

        if (
            is_array($value)
            && count($value) === 2
            && is_string($value[0])
            && is_string($value[1])
        ) {
            return;
        }

        throw new InvalidArgumentException(
            sprintf(
                "The 'callable' key in %s must be a string ('Controller::method') or a two-element array (['Controller', 'method']).",
                $context
            )
        );
    }

    /**
     * Validates that middlewares are provided as a list of strings.
     */
    public function validateMiddlewares(mixed $value, string $context): void
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException(
                sprintf("The 'middlewares' key in %s must be a list of strings.", $context)
            );
        }

        foreach ($value as $index => $middleware) {
            if (!is_string($middleware)) {
                throw new InvalidArgumentException(
                    sprintf("Middleware at index [%d] in %s must be a string (class-string).", $index, $context)
                );
            }
        }
    }

    /**
     * Validates constraint mapping definitions.
     */
    public function validateConstraints(mixed $constraints, string $context): void
    {
        if (!is_array($constraints)) {
            throw new InvalidArgumentException(
                sprintf("The 'constraints' key in %s must be an associative array of parameter => pattern.", $context)
            );
        }

        foreach ($constraints as $param => $pattern) {
            if (!is_string($param) || !is_string($pattern)) {
                throw new InvalidArgumentException(
                    sprintf("Each constraint entry in %s must have a string parameter key and string pattern value.", $context)
                );
            }
        }
    }

    private function assertIsArray(mixed $value, string $context): void
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException(
                sprintf("Route or group definition in %s must be an associative array.", $context)
            );
        }
    }

    private function assertRequiredKeys(array $definition, array $keys, string $context): void
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $definition)) {
                throw new InvalidArgumentException(
                    sprintf("Required key '%s' is missing in %s.", $key, $context)
                );
            }
        }
    }

    private function assertString(mixed $value, string $key, string $context): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException(
                sprintf("Key '%s' in %s must be a string.", $key, $context)
            );
        }

        return $value;
    }

    private function assertSupportedMethod(string $method, string $context): void
    {
        if (!in_array($method, self::SUPPORTED_METHODS, true)) {
            throw new InvalidArgumentException(
                sprintf(
                    "HTTP method '%s' is not supported in %s. Supported methods: %s.",
                    $method,
                    $context,
                    implode(', ', self::SUPPORTED_METHODS)
                )
            );
        }
    }
}
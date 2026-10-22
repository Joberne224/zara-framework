<?php
declare(strict_types=1);

namespace Zara\Container;

use Zara\Container\Exceptions\ParameterNotFoundException;
use Zara\Container\Exceptions\ContainerException;

final class ParameterBag
{
    private const ENV_CAST_TYPES = ['int', 'bool', 'float', 'string'];

    private array $parameters = [];

    public function bind(string $id, mixed $value): void
    {
        $this->parameters[$id] = $value;
    }

    public function get(string $id): mixed
    {
        return $this->resolve($id, []);
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->parameters);
    }

    /**
     * Resolves a parameter id, following %param% interpolation recursively.
     * $seen tracks the chain within a single get() call to detect cycles
     * (e.g. a: '%b%', b: '%a%') instead of overflowing the stack.
     */
    private function resolve(string $id, array $seen): mixed
{
    if ($this->isEnvReference($id)) {
        return $this->resolveEnv(substr($id, 4, -1));
    }

    if (!$this->has($id)) {
        throw new ParameterNotFoundException("Container Error: Parameter '{$id}' not found.");
    }

    if (isset($seen[$id])) {
        throw new ParameterNotFoundException(
            "Container Error: Circular parameter reference detected: " . implode(' -> ', array_keys($seen)) . " -> $id"
        );
    }
    $seen[$id] = true;

    $value = $this->parameters[$id];

    if (is_string($value) && str_contains($value, '%')) {
        // Pure, isolated placeholder (e.g. '%env(int:DB_PORT)%'): preserve
        // the resolved type (int/bool/float) instead of forcing it back to
        // string via preg_replace_callback's string-only return contract.
        if (str_starts_with($value, '%') && str_ends_with($value, '%') && substr_count($value, '%') === 2) {
            return $this->resolve(substr($value, 1, -1), $seen);
        }

        // Mixed string (e.g. 'mysql:host=%env(DB_HOST)%;port=%env(int:DB_PORT)%'):
        // interpolation necessarily produces a string.
        return preg_replace_callback('/%([^%]+)%/', function ($matches) use ($seen) {
            return (string)$this->resolve($matches[1], $seen);
        }, $value);
    }

    return $value;
}
    private function isEnvReference(string $id): bool
    {
        return str_starts_with($id, 'env(') && str_ends_with($id, ')');
    }

    /**
     * Reads an environment variable by name, with an optional cast prefix
     * (e.g. 'int:MAX_CONNECTIONS'). getenv() returns false for an undefined
     * variable — that's never treated as a real value, always an explicit
     * exception, so a missing env var can't silently become false/"" deep
     * inside a service's configuration.
     */
    private function resolveEnv(string $spec): mixed
    {
        $type = 'string';
        $name = $spec;

        if (str_contains($spec, ':')) {
            [$type, $name] = explode(':', $spec, 2);
        }

        if (!in_array($type, self::ENV_CAST_TYPES, true)) {
            throw new ContainerException(
                "Container Error: Unknown env cast type '$type' for '%env($spec)%'. " .
                "Supported types: " . implode(', ', self::ENV_CAST_TYPES) . '.'
            );
        }

        $value = getenv($name);
        if ($value === false) {
            $value = $_ENV[$name] ?? $_SERVER[$name] ?? false;
        }

        if ($value === false) {
            throw new ParameterNotFoundException("Container Error: Environment variable '$name' is not defined.");
        }

        return $this->castEnvValue($type, $name, (string)$value);
    }

    /**
     * Strict casting: a value that does not match the requested type raises an
     * explicit exception instead of being silently cast by PHP (e.g. (int)"hello"
     * === 0, filter_var("maybe", FILTER_VALIDATE_BOOLEAN) === false), which could
     * otherwise let invalid configuration go unnoticed.
     */
    private function castEnvValue(string $type, string $name, string $value): int|float|bool|string
    {
        return match ($type) {
            'int' => is_numeric($value) && (string)(int)$value === trim($value)
                ? (int)$value
                : throw new ContainerException(
                "Container Error: Environment variable '$name' has value '$value', which is not a valid integer for '%env(int:$name)%'."
                ),
            'float' => is_numeric($value)
                ? (float)$value
                : throw new ContainerException(
                "Container Error: Environment variable '$name' has value '$value', which is not a valid float for '%env(float:$name)%'."
                ),
            'bool' => in_array(strtolower(trim($value)), ['true', 'false', '1', '0'], true)
                ? filter_var($value, FILTER_VALIDATE_BOOLEAN)
                : throw new ContainerException(
                "Container Error: Environment variable '$name' has value '$value', which is not a valid boolean for '%env(bool:$name)%'. " .
                "Accepted values: true, false, 1, 0."
                ),
            default => $value, // 'string' : toute valeur est valide
        };
    }
}

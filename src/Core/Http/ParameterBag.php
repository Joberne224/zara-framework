<?php
declare(strict_types=1);

namespace Zara\Core\Http;

class ParameterBag
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private array $data = []
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }

    public function getString(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);
        return is_scalar($value) ? (string) $value : $default;
    }

    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->get($key, $default);
        return is_numeric($value) ? (int) $value : $default;
    }

    public function getBoolean(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);
        return is_bool($value) ? $value : (bool) filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    public function getArray(string $key, array $default = []): array
    {
        $value = $this->get($key, $default);
        return is_array($value) ? $value : $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function with(string $key, mixed $value): self
    {
        $clone = clone $this;
        $clone->data[$key] = $value;
        return $clone;
    }

    public function without(string $key): self
    {
        $clone = clone $this;
        unset($clone->data[$key]);
        return $clone;
    }

    /**
     * @param array<string> $keys
     */
    public function only(array $keys): self
    {
        $clone = clone $this;
        $clone->data = array_intersect_key($clone->data, array_flip($keys));
        return $clone;
    }

    public function isEmpty(): bool
    {
        return empty($this->data);
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function __clone()
    {
        foreach ($this->data as $key => $value) {
            if (is_array($value)) {
                $this->data[$key] = $this->deepCopyArray($value);
            }
        }
    }

    private function deepCopyArray(array $array): array
    {
        $copy = [];
        foreach ($array as $k => $v) {
            $copy[$k] = is_array($v) ? $this->deepCopyArray($v) : $v;
        }
        return $copy;
    }
}
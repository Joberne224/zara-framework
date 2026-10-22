<?php
// file: Zara/Core/Http/Headers.php
declare(strict_types=1);

namespace Zara\Core\Http;
use Zara\Core\Http\Interfaces\HeadersInterface;

class Headers implements HeadersInterface
{
    /** @var array<string, string[]> lowercase name => values */
    private array $headers = [];

    /** @var array<string, string> lowercase name => name as received */
    private array $names = [];

    public function __construct(array $headers = [])
    {
        foreach ($headers as $name => $value) {
            $this->headers[strtolower($name)] = is_array($value) ? $value : [$value];
            $this->names[strtolower($name)] = $name;
        }
    }
    
    public static function createFromServer(array $server): self
    {
        $headers = [];
        foreach ($server as $name => $value) {
            // PHP exposes these headers without the HTTP_ prefix.
            if (in_array($name, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'], true)) {
                $name = 'HTTP_' . $name;
            }

            if (str_starts_with($name, 'HTTP_')) {
                $name = str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower(substr($name, 5)))));
                $headers[$name] = $value;
            }
        }
        return new self($headers);
    }

    public static function createFromGlobals(): self
    {
        return self::createFromServer($_SERVER);
    }

    public function get(string $name): ?string
    {
        $name = strtolower($name);
        return $this->headers[$name][0] ?? null;
    }

    public function getAll(string $name): array
    {
        $name = strtolower($name);
        return $this->headers[$name] ?? [];
    }

    public function has(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    public function with(string $name, mixed $value): self
    {
        $newHeaders = clone $this;
        $newHeaders->headers[strtolower($name)] = is_array($value) ? $value : [$value];
        $newHeaders->names[strtolower($name)] = $name;
        return $newHeaders;
    }

    public function without(string $name): self
    {
        $newHeaders = clone $this;
        unset($newHeaders->headers[strtolower($name)]);
        unset($newHeaders->names[strtolower($name)]);
        return $newHeaders;
    }

    public function toArray(): array
    {
        return $this->headers;
    }

    /**
     * Same as toArray() but keyed by the header names as they were received.
     *
     * @return array<string, string[]>
     */
    public function toArrayPreservingCase(): array
    {
        $result = [];
        foreach ($this->headers as $key => $values) {
            $result[$this->names[$key]] = $values;
        }

        return $result;
    }
}
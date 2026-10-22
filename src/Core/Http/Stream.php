<?php
declare(strict_types=1);

namespace Zara\Core\Http;

use Psr\Http\Message\StreamInterface;
use Zara\Core\Http\Exceptions\StreamException;

final class Stream implements StreamInterface
{
    /** @var resource|null */
    private $resource;

    private bool $seekable;
    private bool $readable;
    private bool $writable;

    /**
     * @param resource $resource Ressource de flux PHP déjà ouverte (fopen()).
     */
    public function __construct($resource)
    {
        if (!is_resource($resource) || get_resource_type($resource) !== 'stream') {
            throw new StreamException('Stream must be constructed from a valid stream resource.');
        }

        $this->resource = $resource;

        $metadata = stream_get_meta_data($resource);
        $this->seekable = $metadata['seekable'];

        [$this->readable, $this->writable] = $this->parseMode($metadata['mode']);
    }

    public static function fromString(string $content): self
    {
        $resource = fopen('php://temp', 'r+b');

        if ($resource === false) {
            throw new StreamException('Failed to open php://temp stream.');
        }

        fwrite($resource, $content);
        rewind($resource);

        return new self($resource);
    }

    public static function fromFile(string $path, string $mode = 'rb'): self
    {
        $resource = @fopen($path, $mode);

        if ($resource === false) {
            throw new StreamException("Failed to open file stream: {$path}");
        }

        return new self($resource);
    }

    public function __toString(): string
    {
        try {
            if ($this->isSeekable()) {
                $this->rewind();
            }
            return $this->getContents();
        } catch (StreamException) {
            return '';
        }
    }

    public function close(): void
    {
        if (is_resource($this->resource)) {
            fclose($this->resource);
        }
        $this->resource = null;
    }

    public function detach()
    {
        $resource = $this->resource;
        $this->resource = null;
        $this->seekable = false;
        $this->readable = false;
        $this->writable = false;

        return $resource;
    }

    public function getSize(): ?int
    {
        if ($this->resource === null) {
            return null;
        }

        $stats = fstat($this->resource);

        return $stats !== false ? $stats['size'] : null;
    }

    public function tell(): int
    {
        $this->assertNotDetached();

        $position = ftell($this->resource);

        if ($position === false) {
            throw new StreamException('Failed to get stream position.');
        }

        return $position;
    }

    public function eof(): bool
    {
        $this->assertNotDetached();

        return feof($this->resource);
    }

    public function isSeekable(): bool
    {
        return $this->seekable;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->assertNotDetached();

        if (!$this->seekable) {
            throw new StreamException('Stream is not seekable.');
        }

        if (fseek($this->resource, $offset, $whence) === -1) {
            throw new StreamException('Failed to seek within stream.');
        }
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        return $this->writable;
    }

    public function write(string $string): int
    {
        $this->assertNotDetached();

        if (!$this->writable) {
            throw new StreamException('Stream is not writable.');
        }

        $written = fwrite($this->resource, $string);

        if ($written === false) {
            throw new StreamException('Failed to write to stream.');
        }

        return $written;
    }

    public function isReadable(): bool
    {
        return $this->readable;
    }

    public function read(int $length): string
    {
        $this->assertNotDetached();

        if (!$this->readable) {
            throw new StreamException('Stream is not readable.');
        }

        $data = fread($this->resource, $length);

        if ($data === false) {
            throw new StreamException('Failed to read from stream.');
        }

        return $data;
    }

    public function getContents(): string
    {
        $this->assertNotDetached();

        if (!$this->readable) {
            throw new StreamException('Stream is not readable.');
        }

        $contents = stream_get_contents($this->resource);

        if ($contents === false) {
            throw new StreamException('Failed to read stream contents.');
        }

        return $contents;
    }

    public function getMetadata(?string $key = null): mixed
    {
        if ($this->resource === null) {
            return $key === null ? [] : null;
        }

        $metadata = stream_get_meta_data($this->resource);

        if ($key === null) {
            return $metadata;
        }

        return $metadata[$key] ?? null;
    }

    /**
     * Déduit readable/writable à partir du mode fopen() (ex. 'r+b', 'wb', 'ab+').
     *
     * @return array{0: bool, 1: bool} [isReadable, isWritable]
     */
    private function parseMode(string $mode): array
    {
        $mode = str_replace(['b', 't'], '', $mode);

        $readable = str_contains($mode, 'r') || str_contains($mode, '+');
        $writable = !str_starts_with($mode, 'r') || str_contains($mode, '+');

        return [$readable, $writable];
    }

    private function assertNotDetached(): void
    {
        if ($this->resource === null) {
            throw new StreamException('Stream is detached or closed.');
        }
    }
}
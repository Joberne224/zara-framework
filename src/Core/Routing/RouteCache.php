<?php

declare(strict_types=1);

namespace Zara\Core\Routing;

use Closure;
use RuntimeException;
use Zara\Core\Routing\Interfaces\RouteCacheInterface;
use Zara\Core\Storage\DiskInterface;
use Zara\Core\Storage\Exceptions\StorageException;

/**
 * Handles persistence of the route registry to the filesystem.
 *
 * @package Zara\Core\Routing
 */
final class RouteCache implements RouteCacheInterface
{
    private const SCHEMA_VERSION = 2;

    public function __construct(
        private readonly DiskInterface $disk,
        private readonly string $path,
    ) {}

    public function exists(): bool
    {
        return $this->disk->exists($this->path);
    }

    public function write(array $routes): void
    {
        $payload = [];

        foreach ($routes as $method => $entries) {
            foreach ($entries as ['route' => $route, 'callable' => $callable]) {
                if ($callable instanceof Closure) {
                    throw new RuntimeException(
                        sprintf(
                            "Cannot cache route '%s': Closure callables are not supported by the route cache. Use a string or [Controller::class, 'method'] array instead.",
                            $route->getPath()
                        )
                    );
                }

                $payload[$method][] = [
                    'path'        => $route->getPath(),
                    'callable'    => $callable,
                    'namespace'   => $route->getNamespace(),
                    'name'        => $route->getName(),
                    'middlewares' => $route->getMiddlewares(),
                    'constraints' => $route->getConstraints(),
                ];
            }
        }

        $envelope = [
            '_version' => self::SCHEMA_VERSION,
            'routes'   => $payload,
        ];

        $content = "<?php\n\nreturn " . var_export($envelope, true) . ";\n";

        try {
            $this->disk->write($this->path, $content);
        } catch (StorageException $e) {
            throw new RuntimeException(
                sprintf("Unable to write the route cache to '%s'.", $this->path),
                previous: $e
            );
        }
    }

    public function read(): array
    {
        if (!$this->exists()) {
            throw new RuntimeException(sprintf("Route cache file '%s' not found.", $this->path));
        }

        $envelope = include $this->disk->path($this->path);

        if (!is_array($envelope) || !isset($envelope['_version'], $envelope['routes'])) {
            throw new RuntimeException(
                sprintf("Route cache file '%s' is in an outdated or corrupted format.", $this->path)
            );
        }

        if ($envelope['_version'] !== self::SCHEMA_VERSION) {
            throw new RuntimeException(
                sprintf(
                    "Cache '%s' was generated with an incompatible schema (v%s, expected v%d).",
                    $this->path,
                    var_export($envelope['_version'], true),
                    self::SCHEMA_VERSION
                )
            );
        }

        return $this->hydrate($envelope['routes']);
    }

    public function clear(): void
    {
        if ($this->exists()) {
            $this->disk->delete($this->path);
        }
    }

    public function getPath(): string
    {
        return $this->path;
    }

    private function hydrate(array $payload): array
    {
        $routes = [];

        foreach ($payload as $method => $entries) {
            foreach ($entries as $data) {
                $route = new Route($data['path']);

                if (!empty($data['namespace'])) {
                    $route->setNamespace($data['namespace']);
                }

                if (!empty($data['name'])) {
                    $route->name($data['name']);
                }

                if (!empty($data['middlewares'])) {
                    $route->setMiddlewares($data['middlewares']);
                }

                foreach ($data['constraints'] ?? [] as $param => $pattern) {
                    $route->with($param, $pattern);
                }

                $routes[$method][] = [
                    'route'    => $route,
                    'callable' => $data['callable'],
                ];
            }
        }

        return $routes;
    }
}
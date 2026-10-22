<?php

declare(strict_types=1);

namespace Zara\Core\Routing\Loader;

use InvalidArgumentException;
use RuntimeException;
use Zara\Core\Routing\Router;
use Zara\Core\Routing\Yaml\YamlRouteValidator;
use Zara\Core\Support\YamlParser;
use Zara\Core\Routing\Loader\AttributeRouteLoader;

/**
 * Loads routes and route groups declared in a YAML file into an existing Router instance.
 *
 * Single Responsibility: Orchestrates route loading by leveraging YamlParser for safe file parsing,
 * YamlRouteValidator for structural verification, and registering resolved routes onto Router.
 */
final class YamlRouteLoader implements RouteLoaderInterface
{
    private readonly AttributeRouteLoader $attributeLoader;

    public function __construct(
        private readonly Router $router,
        private readonly YamlParser $yamlParser = new YamlParser(),
        private readonly YamlRouteValidator $validator = new YamlRouteValidator(),
        ?AttributeRouteLoader $attributeLoader = null,
    ) {
        $this->attributeLoader = $attributeLoader ?? new AttributeRouteLoader($this->router);
    }

    public function load(string $filePath): void
    {
        $data = $this->yamlParser->parse($filePath);

        if (empty($data)) {
            throw new RuntimeException(sprintf("YAML routing file '%s' is empty or invalid.", $filePath));
        }

        // Process automatically scanned attribute controllers
        if (isset($data['controllers'])) {
            foreach ($data['controllers'] as $controllerConfig) {
                $directory = $controllerConfig['resource'];
                $namespace = $controllerConfig['namespace'];

               $this->attributeLoader->loadDirectory($directory, $namespace);
            }
        }

        if (isset($data['routes'])) {
            $this->loadRoutes($data['routes'], $filePath);
        }

        if (isset($data['groups'])) {
            $this->loadGroups($data['groups'], $filePath);
        }
    }
    
    /**
     * Checks if this loader supports the given file format based on extension.
     *
     * @param string $filePath File path to check.
     */
    public function supports(string $filePath): bool
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return in_array($extension, ['yaml', 'yml'], true);
    }
    /**
     * Processes flat routes list.
     */
    private function loadRoutes(mixed $routes, string $context): void
    {
        if (!is_array($routes)) {
            throw new InvalidArgumentException(
                sprintf("The 'routes' key in '%s' must be a list.", $context)
            );
        }

        foreach ($routes as $index => $definition) {
            $this->registerRoute($definition, sprintf("%s > routes[%s]", $context, $index));
        }
    }

    /**
     * Registers a single route definition onto the Router.
     */
    private function registerRoute(mixed $definition, string $context): void
    {
        $this->validator->validateRoute($definition, $context);

        $path      = (string) $definition['path'];
        $method    = strtoupper((string) $definition['method']);
        $callable  = $definition['callable'];
        $namespace = isset($definition['namespace']) ? (string) $definition['namespace'] : null;

        if ($namespace !== null) {
            $this->router->group(['prefix' => '', 'namespace' => $namespace], function () use (
                $path, $method, $callable, $definition, $context
            ): void {
                $this->registerRouteInner($path, $method, $callable, $definition, $context);
            });

            return;
        }

        $this->registerRouteInner($path, $method, $callable, $definition, $context);
    }

    private function registerRouteInner(
        string $path,
        string $method,
        mixed $callable,
        array $definition,
        string $context
    ): void {
        if ($method === 'ANY') {
            $this->router->any($path, $callable);
            return;
        }

        $route = match ($method) {
            'GET'     => $this->router->get($path, $callable),
            'POST'    => $this->router->post($path, $callable),
            'PUT'     => $this->router->put($path, $callable),
            'DELETE'  => $this->router->delete($path, $callable),
            'PATCH'   => $this->router->patch($path, $callable),
            'OPTIONS' => $this->router->options($path, $callable),
            default   => throw new InvalidArgumentException(
                sprintf("HTTP method '%s' is not supported in %s.", $method, $context)
            ),
        };

        if (!empty($definition['middlewares'])) {
            $route->setMiddlewares(array_values($definition['middlewares']));
        }

        if (!empty($definition['constraints'])) {
            $route->where($definition['constraints']);
        }
    }

    /**
     * Processes route groups recursively.
     */
    private function loadGroups(mixed $groups, string $context): void
    {
        if (!is_array($groups)) {
            throw new InvalidArgumentException(
                sprintf("The 'groups' key in '%s' must be a list.", $context)
            );
        }

        foreach ($groups as $index => $definition) {
            $this->registerGroup($definition, sprintf("%s > groups[%s]", $context, $index));
        }
    }

    private function registerGroup(mixed $definition, string $context): void
    {
        $this->validator->validateGroup($definition, $context);

        $options = [
            'prefix' => (string) $definition['prefix'],
        ];

        if (isset($definition['namespace'])) {
            $options['namespace'] = (string) $definition['namespace'];
        }

        if (isset($definition['middlewares'])) {
            $options['middlewares'] = array_values($definition['middlewares']);
        }

        $this->router->group($options, function (Router $router) use ($definition, $context): void {
            if (isset($definition['routes'])) {
                $this->loadRoutes($definition['routes'], $context);
            }

            if (isset($definition['groups'])) {
                $this->loadGroups($definition['groups'], $context);
            }
        });
    }
}
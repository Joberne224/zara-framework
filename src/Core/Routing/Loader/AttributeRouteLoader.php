<?php

declare(strict_types=1);

namespace Zara\Core\Routing\Loader;

use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Zara\Core\Routing\Attributes\Route;
use Zara\Core\Routing\Interfaces\RouterInterface;
use Zara\Core\Routing\Scanner\ControllerDirectoryScanner;

/**
 * Inspects controller classes via PHP Reflection and registers routes declared with #[Route] attributes.
 */
final class AttributeRouteLoader
{
    public function __construct(
        private readonly RouterInterface $router,
        private readonly ControllerDirectoryScanner $scanner = new ControllerDirectoryScanner()
    ) {}

    /**
     * Scans a directory and registers attribute routes from all discovered controllers.
     *
     * @param string $directory Absolute directory path.
     * @param string $baseNamespace Base PSR-4 namespace corresponding to the directory.
     */
    public function loadDirectory(string $directory, string $baseNamespace): void
    {
        $classes = $this->scanner->scan($directory, $baseNamespace);
        $this->loadClasses($classes);
    }

    /**
     * Inspects a list of controller classes and registers their attribute routes onto the Router.
     *
     * @param list<class-string> $controllerClasses
     */
    public function loadClasses(array $controllerClasses): void
    {
        foreach ($controllerClasses as $class) {
            $this->loadClass($class);
        }
    }

    /**
     * Inspects a single controller class for route attributes.
     *
     * @param class-string $className
     */
    public function loadClass(string $className): void
    {
        if (!class_exists($className)) {
            throw new RuntimeException(
                sprintf("AttributeRouteLoader Error: Controller class '%s' does not exist.", $className)
            );
        }

        $reflectionClass = new ReflectionClass($className);
        
        $classRoute = $this->getClassRouteAttribute($reflectionClass);
        $classPrefix = $classRoute?->path ?? '';
        $classMiddlewares = $classRoute?->middlewares ?? [];
        $classNamePrefix = $classRoute?->name ?? '';

        foreach ($reflectionClass->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $routeAttributes = $method->getAttributes(Route::class, \ReflectionAttribute::IS_INSTANCEOF);

            foreach ($routeAttributes as $attribute) {
                /** @var Route $routeAttr */
                $routeAttr = $attribute->newInstance();

                $fullPath = '/' . trim(trim($classPrefix, '/') . '/' . trim($routeAttr->path, '/'), '/');
                $combinedMiddlewares = array_merge($classMiddlewares, $routeAttr->middlewares);
                $routeName = ($classNamePrefix !== '' && $routeAttr->name !== null)
                    ? $classNamePrefix . $routeAttr->name
                    : $routeAttr->name;

                $callable = [$className, $method->getName()];

                foreach ($routeAttr->methods as $httpMethod) {
                    $this->registerRoute(
                        $httpMethod,
                        $fullPath,
                        $callable,
                        $routeName,
                        $combinedMiddlewares,
                        $routeAttr->constraints
                    );
                }
            }
        }
    }

    private function getClassRouteAttribute(ReflectionClass $reflectionClass): ?Route
    {
        $attributes = $reflectionClass->getAttributes(Route::class, \ReflectionAttribute::IS_INSTANCEOF);

        return !empty($attributes) ? $attributes[0]->newInstance() : null;
    }

    private function registerRoute(
        string $method,
        string $path,
        array $callable,
        ?string $name,
        array $middlewares,
        array $constraints
    ): void {
        $route = match ($method) {
            'GET'     => $this->router->get($path, $callable),
            'POST'    => $this->router->post($path, $callable),
            'PUT'     => $this->router->put($path, $callable),
            'DELETE'  => $this->router->delete($path, $callable),
            'PATCH'   => $this->router->patch($path, $callable),
            'OPTIONS' => $this->router->options($path, $callable),
            'ANY'     => null,
            default   => throw new RuntimeException(sprintf("Unsupported HTTP method '%s' in attribute.", $method)),
        };

        if ($method === 'ANY') {
            $this->router->any($path, $callable);
            return;
        }

        if ($name !== null) {
            $route->name($name);
        }

        if (!empty($middlewares)) {
            $route->setMiddlewares($middlewares);
        }

        if (!empty($constraints)) {
            $route->where($constraints);
        }
    }
}

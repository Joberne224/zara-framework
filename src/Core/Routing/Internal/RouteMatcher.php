<?php
declare(strict_types=1);

namespace Zara\Core\Routing\Internal;

use RuntimeException;
use Zara\Core\Routing\ControllerAction;
use Zara\Core\Routing\Route;
use Zara\Core\Routing\RouteMatch;
use Zara\Core\Routing\Interfaces\RouteMatcherInterface;
use Zara\Core\Routing\Interfaces\RouteMatchInterface;
use Zara\Core\Routing\Interfaces\RouteInfoInterface;
use Zara\Core\Routing\Exceptions\RequestMethodNotFoundException;
use Zara\Core\Routing\Exceptions\RouteNotFoundException;

/**
 * Matches an HTTP method and URI against registered routes and resolves target controllers.
 *
 * Single Responsibility: Matching and callable resolution.
 * RouteMatcher is stateless: it receives routes per call rather than storing them,
 * making it trivially testable and safe in persistent process environments.
 *
 * @internal Implementation detail of Router. Do not depend on this class directly.
 * @package Zara\Core\Routing\Internal
 */
final class RouteMatcher implements RouteMatcherInterface
{   /**
     * @param array<string, list<array{route: Route, callable: string|array|callable}>> $routes
     */
    public function matchPath(
        array  $routes,
        string $method,
        string $uri,
        string $defaultNamespace
    ): RouteMatchInterface {
        $method = strtoupper($method);
        $url    = trim($uri, '/');

        // RFC 9110: HEAD falls back to GET if no explicit HEAD route is registered
        $lookupMethod = ($method === 'HEAD' && !isset($routes['HEAD'])) ? 'GET' : $method;

        if (isset($routes[$lookupMethod])) {
            foreach ($routes[$lookupMethod] as ['route' => $route, 'callable' => $callable]) {
                $params = $route->tryMatch($url);

                if ($params !== null) {
                    return $this->resolveRouteInfo($route->toRouteInfo($params), $callable, $defaultNamespace);
                }
            }
        }

        // Check if path matches under a different HTTP method for proper HTTP 405 response
        $allowedMethods = $this->allowedMethodsFor($routes, $url, exclude: $method);

        if ($allowedMethods !== []) {
            throw new RequestMethodNotFoundException(
                sprintf(
                    "Method '%s' is not allowed for '%s'. Allowed methods: %s.",
                    $method,
                    $url,
                    implode(', ', $allowedMethods)
                ),
                $allowedMethods
            );
        }

        throw new RouteNotFoundException(sprintf("No route matches '%s'.", $url));
    }

    /**
     * Finds all HTTP methods that accept the target URL.
     *
     * @param array<string, list<array{route: Route, callable: string|array|callable}>> $routes
     * @return list<string>
     */
    private function allowedMethodsFor(array $routes, string $url, string $exclude): array
    {
        $allowed = [];

        foreach ($routes as $candidateMethod => $entries) {
            if ($candidateMethod === $exclude) {
                continue;
            }

            foreach ($entries as ['route' => $route]) {
                if ($route->tryMatch($url) !== null) {
                    $allowed[] = $candidateMethod;
                    break;
                }
            }
        }

        return $allowed;
    }

    private function resolveRouteInfo(
        RouteInfoInterface $routeInfo,
        string|array|callable $callable,
        string $defaultNamespace
    ): RouteMatchInterface {
        $namespace = $routeInfo->getNamespace() !== ''
            ? $routeInfo->getNamespace()
            : $defaultNamespace;
        $params = $routeInfo->getParams();
        $middlewares = $routeInfo->getMiddlewares();

        $controller = $this->resolveController($callable, $namespace);

        if ($controller !== null) {
            return new RouteMatch(
                $params,
                $middlewares,
                $callable,
                $namespace,
                $controller->class,
                $controller->method
            );
        }

        if (!is_callable($callable)) {
            throw new RuntimeException(
                "Invalid callable format. Expected 'Controller::method', 'Controller', ['Controller', 'method'], or a valid callable."
            );
        }

        return new RouteMatch($params, $middlewares, $callable, $namespace);
    }

    private function resolveController(
        string|array|callable $callable,
        string $namespace
    ): ?ControllerAction {

        if (is_string($callable)) {
            if (!str_contains($callable, '::')) {
                $className = $this->qualifyController($callable, $namespace);

                if (!class_exists($className) && is_callable($callable)) {
                    return null;
                }
            }
        } elseif (!is_array($callable)) {
            return null;
        } elseif (
            (!array_is_list($callable) || !in_array(count($callable), [1, 2], true)
                || array_filter($callable, static fn (mixed $part): bool => !is_string($part)) !== [])
            && is_callable($callable)
        ) {
            return null;
        }

        [$controller, $method] = $this->normalizeControllerNotation($callable);

        return $this->validateController($controller, $method, $namespace);
    }

    /**
     * @return array{0: string, 1: string}
     * @throws \InvalidArgumentException If the notation is malformed.
     */
    private function normalizeControllerNotation(string|array $callable): array
    {
        if (is_string($callable)) {
            if (str_contains($callable, '::')) {
                $parts = explode('::', $callable);

                if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
                    throw new \InvalidArgumentException(
                        "Invalid controller definition: expected 'Class::method' with a class and method name."
                    );
                }

                return [$parts[0], $parts[1]];
            }

            return [$callable, '__invoke'];
        }
        
        if (array_is_list($callable)) {
            if (
                count($callable) === 2
                && is_string($callable[0])
                && $callable[0] !== ''
                && is_string($callable[1])
                && $callable[1] !== ''
            ) {
                return [$callable[0], $callable[1]];
            }

            if (count($callable) === 1 && is_string($callable[0]) && $callable[0] !== '') {
                return [$callable[0], '__invoke'];
            }
        }

        throw new \InvalidArgumentException(
            "Invalid controller definition: expected 'Class', 'Class::method', [ 'Class' ] or ['Class', 'method'], got " . var_export($callable, true)
        );
    }

    private function validateController(
        string $controller,
        string $method,
        string $namespace
    ): ControllerAction {
        $fullController = str_contains($controller, '\\')
            ? $controller
            : $this->qualifyController($controller, $namespace);

        if (!class_exists($fullController)) {
            throw new RuntimeException(sprintf("Controller class '%s' not found.", $fullController));
        }

        if (!method_exists($fullController, $method)) {
            throw new RuntimeException(sprintf("Method '%s' does not exist on '%s'.", $method, $fullController));
        }

        $reflection = new \ReflectionMethod($fullController, $method);
        if (!$reflection->isPublic() || $reflection->isConstructor() || $reflection->isDestructor()) {
            throw new RuntimeException(sprintf(
                "Route action '%s::%s()' must be public and cannot be a constructor or destructor.",
                $fullController,
                $method,
            ));
        }

        return new ControllerAction($fullController, $method);
    }

    private function qualifyController(string $controller, string $namespace): string
    {
        return trim($namespace, '\\') !== ''
            ? trim($namespace, '\\') . '\\' . $controller
            : $controller;
    }
}

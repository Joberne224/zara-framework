<?php
declare(strict_types=1);

namespace Zara\Container\Factory;

use Psr\Container\ContainerInterface;
use Zara\Container\ServiceRegistry;
use Zara\Container\ParameterBag;
use Zara\Container\Exceptions\ContainerException;
use Zara\Core\LazyServiceProxy;

final class ArgumentResolver
{
    public function __construct(
        private ContainerInterface $container,
        private ServiceRegistry $registry,
        private ParameterBag $parameters,
    ) {}

    public function resolveArguments(array $arguments, string $context = ''): array
    {
        $out = [];
        foreach ($arguments as $index => $arg) {
            $out[$index] = $this->resolveArgument($arg, $index, $context);
        }
        return $out;
    }

    /**
     * Resolves one argument value using conventions shared with the YAML
     * service file: '@service', '%param%', and '!tagged:tag'. Extracted from
     * resolveArguments() for reuse, especially by #[Inject] (ParameterResolver
     * and PropertyInjector), so they use the same syntax without duplicating parsing.
     */
    public function resolveArgument(mixed $arg, int|string $index = 0, string $context = ''): mixed
    {
        if (!is_string($arg)) {
            return $arg;
        }

        if (str_starts_with($arg, '@lazy:')) {
            $serviceId = substr($arg, 6);
            if ($this->container->has($serviceId) || (!$this->registry->isStrictMode() && class_exists($serviceId))) {
                return $this->resolveLazyReference($serviceId);
            }

            throw new ContainerException(
                "Container Error: Unable to resolve lazy reference '$serviceId' for argument '" .
                (is_int($index) ? "#$index" : (string)$index) . "'. $context"
            );
        }

        if (str_starts_with($arg, '@')) {
            $serviceId = substr($arg, 1);
            if ($this->container->has($serviceId) || (!$this->registry->isStrictMode() && class_exists($serviceId))) {
                return $this->resolveSingleService($serviceId);
            }

            throw new ContainerException(
                "Container Error: Unable to resolve dependency '$serviceId' for argument '" .
                (is_int($index) ? "#$index" : (string)$index) . "'. $context"
            );
        }

        if (str_starts_with($arg, '!tagged:')) {
            $tagName = substr($arg, 8);
            $instances = [];
            foreach ($this->registry->getTagged($tagName) as $sid) {
                $instances[] = $this->resolveSingleService($sid);
            }
            return $instances;
        }

        if (str_contains($arg, '%')) {
            if (str_starts_with($arg, '%') && str_ends_with($arg, '%') && substr_count($arg, '%') === 2) {
                return $this->parameters->get(substr($arg, 1, -1));
            }

            return preg_replace_callback('/%([^%]+)%/', function ($matches) {
                return (string)$this->parameters->get($matches[1]);
            }, $arg);
        }

        if (str_starts_with($arg, 'tagged:') && !str_starts_with($arg, '!tagged:')) {
            throw new ContainerException(
                "Container Error: Suspicious argument '" .
                (is_int($index) ? "#$index" : (string)$index) .
                "': value '$arg' looks like a tagged reference but is missing the '!' prefix. " .
                "Did you mean '!$arg'? $context"
            );
        }

        return $arg;
    }

    /**
     * Resolves a single service by ID. If no definition exists but the class
     * does (implicit autowiring), register it on demand before resolving it;
     * otherwise Container::get() would throw "definition not found".
     *
     * Since the lazy proxy fix (see ServiceFactory::get()), Container::get()
     * always returns the real service instance, never a LazyServiceProxy. The
     * return type can therefore remain `object` without risking an object
     * that is not substitutable for the expected type (e.g. one that does not
     * implement the declared interface). For an explicitly deferred reference,
     * see resolveLazyReference().
     *
     * @throws ContainerException If the service does not exist.
     */
    public function resolveSingleService(string $serviceId): object
    {
        if (!$this->container->has($serviceId)) {
            $this->registry->autoRegisterIfPossible($serviceId);
        }
        
        return $this->container->get($serviceId);
        
    }

    /**
     * Resolves an explicitly deferred service reference using the
     * '@lazy:ServiceId' syntax. Unlike resolveSingleService(), it does not
     * construct the service immediately; it returns a LazyServiceProxy that
     * constructs the real service on the first method call.
     *
     * This is now the only way to obtain a lazy proxy; one is no longer
     * returned implicitly because the target definition has 'lazy: true' (see
     * ServiceFactory::get()). The caller deliberately opts in, typically to
     * break a dependency cycle. The corresponding property or parameter must
     * use LazyServiceProxyInterface (or a compatible union), never the concrete
     * service type: the proxy is not substitutable for either except through
     * method calls (__call()).
     */
    public function resolveLazyReference(string $serviceId): LazyServiceProxy
    {
        if (!$this->container->has($serviceId)) {
            $this->registry->autoRegisterIfPossible($serviceId);
        }

        return new LazyServiceProxy(
            $this->container,
            $serviceId,
            static function (ContainerInterface $container, string $id): object {
                return $container->get($id);
            }
        );
    }
}

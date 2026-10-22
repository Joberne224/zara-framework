<?php
declare(strict_types=1);

namespace Zara\Container;

use Zara\Container\Compiler\CompiledContainerLoader;
use Zara\Container\Compiler\CompiledContainerWriter;
use Zara\Container\Compiler\CompiledFactoryAdapter;
use Zara\Core\Config\Env;
use Zara\Core\Support\Logging\LoggerDecorator;
use Psr\Log\NullLogger;
use Psr\Container\ContainerInterface;
use Zara\Container\Compiler\Interfaces\ContainerLoaderInterface;
use Zara\Container\Interfaces\ServiceFactoryInterface;
use Psr\Log\LoggerInterface;
use Zara\Core\Http\Interfaces\RequestInterface;
use Zara\Container\Interfaces\RequestBinderInterface;
use Zara\Container\Exceptions\ContainerException;

final class Container implements ContainerInterface, RequestBinderInterface
{
    private ServiceRegistry         $registry;
    private ParameterBag            $parameters;
    private ReflectionCache         $reflectionCache;
    private ServiceFactoryInterface $factory;
    private ConfigLoader            $configLoader;
    private DependencyValidator     $validator;
    private LoggerInterface         $logger;

    private const SELF_IDS = [
        ContainerInterface::class,
        self::class,
    ];

    public function __construct(string $projectRoot, ?LoggerInterface $logger = null)
    { 
        Env::init();  
        $this->parameters = new ParameterBag();
        $root = $this->assertValidProjectRoot($projectRoot);

        $this->parameters->bind('root', $root);
        $this->parameters->bind('ROOT', $root);
        $this->parameters->bind('app.debug', Env::isDebug());
        $this->parameters->bind('app.env', Env::getEnv());

        // Wrap the supplied logger (or NullLogger by default) in a resilient decorator.
        // Network or disk I/O errors while logging during loadFromFile() will not break bootstrap.
        $rawLogger = $logger ?? new NullLogger();
        $this->logger = new LoggerDecorator($rawLogger);

        $this->reflectionCache = new ReflectionCache();
        $this->registry        = new ServiceRegistry();
        $this->factory         = new ServiceFactory($this, $this->registry, $this->parameters, $this->reflectionCache);
        $this->configLoader    = new ConfigLoader($this, $this->registry, $this->parameters, $this->logger);
        $this->validator       = new DependencyValidator($this->registry);
    }

    public function useCompiledContainerIfAvailable(
        string $compiledFile,
        string $sourceConfigFile,
        ?ContainerLoaderInterface $loader = null,
    ): self {
        if (!file_exists($sourceConfigFile)) {
            return $this;
        }

        $loader ??= new CompiledContainerLoader(
            new CompiledContainerWriter(),
            $this->registry,
            $this->reflectionCache,
        );

        $compiled = $loader->load($compiledFile, filemtime($sourceConfigFile), $this->parameters);

        $this->factory = new CompiledFactoryAdapter(
            $compiled,
            $this->factory,
            $this->registry,
            $this->parameters
        );

        return $this;
    }

    public function get(string $id): mixed
    {
        if (in_array($id, self::SELF_IDS, true)) {
            return $this;
        }

        $this->registry->assertAccessibleExternally($id);

        if (!$this->factory->isInsideResolution() && !$this->registry->isPublic($id)) {
            throw new ContainerException(
                "Container Error: Service '$id' is private (public: false) and cannot be retrieved directly. " .
                "It can only be injected as a dependency of another service."
            );
        }

        return $this->factory->get($id);
    }

    public function has(string $id): bool
    {
        if (in_array($id, self::SELF_IDS, true)) {
            return true;
        }

        $this->registry->assertAccessibleExternally($id);
        
        return $this->registry->isAlias($id)
               || $this->registry->hasDefinition($id)
               || $this->registry->hasInstance($id)
               || $this->registry->hasResolvableService($id);
    }

    public function bind(
        string $id,
        callable|string|array $callable,
        string $scope = 'prototype'
    ): self {
        $this->registry->bind($id, $callable, $scope);
        return $this;
    }


    /**
     * Replaces the internal logger used by the container and its components
     * (such as ConfigLoader) without breaking existing references.
     */
    public function setLogger(LoggerInterface $logger): self
    {
        if ($this->logger instanceof LoggerDecorator) {
            $this->logger->setInnerLogger($logger);
        } else {
            $this->logger = new LoggerDecorator($logger);
        }

        return $this;
    }

    /**
     * Declares $id as a synthetic service: known to the container, but never
     * constructed by it. It must be provided through set() before retrieval.
     * This call is optional because set() declares the placeholder when needed;
     * use it mainly to document intent early (e.g. during bootstrap, before the
     * actual value is available).
     */
    public function synthetic(string $id): self
    {
        $this->registry->assertAccessibleExternally($id);
        $this->registry->define($id, ['synthetic' => true]);
        return $this;
    }

    /**
     * Provides the actual instance for a synthetic service. Declares the
     * placeholder if synthetic()/define() has not already been called for this
     * ID, so set() can be used on its own without a required setup step.
     */
    public function set(string $id, object $instance): self
    {
        $this->registry->assertCanSetInstance($id);
        if (!$this->registry->hasDefinition($id) && !$this->registry->isAlias($id)) {
            $this->registry->define($id, ['synthetic' => true]);
        }

        $this->registry->setInstance($id, $instance);
        if ($this->factory instanceof CompiledFactoryAdapter) {
            $this->factory->setInstance($this->registry->resolveAlias($id), $instance);
        }
        return $this;
    }


    /**
     * Declare an alias for a service. The alias can be used to retrieve the target service.
     * @param string $alias The alias name.
     * @param string $target The target service ID.
     * @throws ContainerException if the target service does not exist.
     * @return self
     */
    public function alias(string $alias, string $target): self
    {
        $this->registry->setAlias($alias, $target);
        return $this;
    }

    public function define(string $id, array $definition): self
    {
        $this->registry->assertAccessibleExternally($id);
        $this->registry->define($id, $definition);

        if (!empty($definition['tags'])) {
            $this->registry->addTags($id, $definition['tags']);
        }

        if (($definition['preload'] ?? false) && ($definition['scope'] ?? 'prototype') === 'singleton') {
            $this->get($id);
        }

        return $this;
    }

    public function singleton(string $id, callable|string|array $callable): mixed
    {
        $this->registry->bind($id, $callable, 'singleton');
        return $this;
    }
    public function bindParameter(string $id, mixed $value): self
    {
        $this->parameters->bind($id, $value);
        return $this;
    }

    /**
     * Republishes the current Request instance as the synthetic
     * RequestInterface service. Required because Request is immutable:
     * every withAttribute()/withUri()/... call yields a new instance, and
     * the container's copy of a synthetic service is never updated on its
     * own — it only ever holds whatever was last passed to set().
     */
    public function rebindRequest(RequestInterface $request): void
    {
        $this->registry->assertCanSetInstance(RequestInterface::class);
        $this->registry->setInstance(RequestInterface::class, $request);
        if ($this->factory instanceof CompiledFactoryAdapter) {
            $this->factory->setInstance(RequestInterface::class, $request);
        }
    }

    public function hasParameter(string $id): bool
    {
        return $this->parameters->has($id);
    }

    public function getParameter(string $id): mixed
    {
        return $this->parameters->get($id);
    }

    public function loadFromFile(string $file): self
    {
        $this->configLoader->loadFromFile($file, $this->validator);
        return $this;
    }

    public function clearReflectionCache(): void
    {
        $this->reflectionCache->clear();
    }

    public function enableStrictMode(): self
    {
        $this->registry->enableStrictMode();
        return $this;
    }

    public function getRegistry(): ServiceRegistry
    {
        return $this->registry;
    }

    public function getParameterBag(): ParameterBag
    {
        return $this->parameters;
    }

    public function getReflectionCache(): ReflectionCache
    {
        return $this->reflectionCache;
    }

    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    private function assertValidProjectRoot(string $projectRoot): string
    {
        if (empty(trim($projectRoot)) || !is_dir($projectRoot)) {
            throw new \InvalidArgumentException(
                sprintf("Zara Container Error: The specified project root '%s' is invalid or does not exist.", $projectRoot)
            );
        }

        return realpath($projectRoot);
    }
}

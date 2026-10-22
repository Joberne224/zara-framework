<?php
declare(strict_types=1);

namespace Zara\Bootstrap;

use Psr\Log\LoggerInterface;
use Zara\View\Interfaces\TemplateEngineInterface;
use Psr\Log\NullLogger;
use Zara\Container\Container;
use Zara\Core\Config\Env;
use Zara\Core\Error\ErrorHandler;
use Zara\View\Engine\TemplateEngine;
use Zara\View\Engine\TemplateEngineConfig;

/**
 * Stateful, object-oriented entry point for bootstrapping a Zara application.
 *
 * The project root is supplied by the application and is never changed. An
 * optional service configuration file and customization callback can be set
 * before boot. A successful boot creates one container for this Application
 * instance; subsequent boot() calls return that same container.
 */
final class Application
{
    private ?string $configFile = null;
    private ?\Closure $configureCallback = null;
    private ?Container $container = null;
    private bool $booting = false;

    public function __construct(private readonly string $projectRoot)
    {
    }

    /** Set the optional YAML or PHP service configuration file. */
    public function withConfigFile(string $configFile): self
    {
        $this->assertNotBooted();
        $this->configFile = $configFile;

        return $this;
    }

    /** Register a callback that can add or replace container definitions. */
    public function configure(callable $callback): self
    {
        $this->assertNotBooted();
        $this->configureCallback = \Closure::fromCallable($callback);

        return $this;
    }

    /** Bootstrap the application and return its service container. */
    public function boot(): Container
    {
        if ($this->container !== null) {
            return $this->container;
        }
        if ($this->booting) {
            throw new \LogicException('Application boot() cannot be called recursively.');
        }

        $this->booting = true;

        try {
            $projectRoot = realpath($this->projectRoot);
            if ($projectRoot === false || !is_dir($projectRoot)) {
                throw new \InvalidArgumentException(sprintf(
                    'Project root "%s" does not exist or is not a directory.',
                    $this->projectRoot,
                ));
            }

            Env::init();
            $debug = Env::isDebug();

            // Register an early handler so failures during bootstrap are captured.
            ErrorHandler::create(debug: $debug, editor: 'vscode')->register();

            $container = new Container($projectRoot);

            // Install a PSR-3 fallback only when the application has not supplied one.
            if (!$container->getRegistry()->hasDefinition(LoggerInterface::class)) {
                $container->define(LoggerInterface::class, [
                    'callable' => static fn(): LoggerInterface => new NullLogger(),
                    'scope'    => 'singleton',
                    'public'   => true,
                ]);
            }

            // Register framework defaults. Applications can override these in
            // their service config or configure callback.
            $container->define(TemplateEngineConfig::class, [
                'callable' => static fn(): TemplateEngineConfig => new TemplateEngineConfig(
                    basePath: $projectRoot . '/resources/views',
                    cachePath: $projectRoot . '/storage/cache/views',
                    projectRoot: $projectRoot,
                    router: null,
                    baseUrl: '',
                    allowedExtensions: ['php', 'html', 'tpl'],
                    debug: $debug,
                ),
                'scope'  => 'singleton',
                'public' => true,
            ]);

            $container->define(TemplateEngineInterface::class, [
                'callable' => TemplateEngine::class,
                'scope'    => 'singleton',
                'public'   => true,
            ]);

            $container->bindParameter(
                'container.cache_file',
                $projectRoot . '/storage/cache/container/services-cache.php',
            );
            $container->bindParameter('container.cache_enable', !$debug);

            // Snapshot framework definitions so accidental overrides can be reported.
            $coreIds = [TemplateEngineConfig::class, TemplateEngineInterface::class];
            $before = [];
            foreach ($coreIds as $id) {
                $before[$id] = $container->getRegistry()->hasDefinition($id)
                    ? $container->getRegistry()->getDefinition($id)
                    : null;
            }

            if ($this->configFile !== null) {
                $container->loadFromFile($this->configFile);
            }

            if ($this->configureCallback !== null) {
                ($this->configureCallback)($container);
            }

            /** @var LoggerInterface $appLogger */
            $appLogger = $container->get(LoggerInterface::class);
            $container->setLogger($appLogger);
            $resilientLogger = $container->getLogger();

            foreach ($coreIds as $id) {
                $after = $container->getRegistry()->hasDefinition($id)
                    ? $container->getRegistry()->getDefinition($id)
                    : null;

                if ($after !== $before[$id]) {
                    $resilientLogger->warning(
                        "[Zara Application] Core service '$id' was overridden during boot(). "
                        . 'Check for an unintended service ID collision.'
                    );
                }
            }

            // Use the original PSR-3 logger so ErrorHandler can detect NullLogger
            // and retain its error_log() fallback.
            ErrorHandler::create(debug: $debug, logger: $appLogger, editor: 'vscode')->register();

            $this->container = $container;
            return $container;
        } finally {
            $this->booting = false;
        }
    }

    /** Return this application's container after a successful boot. */
    public function getContainer(): Container
    {
        if ($this->container === null) {
            throw new \LogicException('Application must complete boot() before its container can be retrieved.');
        }

        return $this->container;
    }

    private function assertNotBooted(): void
    {
        if ($this->container !== null || $this->booting) {
            throw new \LogicException('Application configuration cannot be changed after boot() has started.');
        }
    }
}

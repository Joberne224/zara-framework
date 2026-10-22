<?php

declare(strict_types=1);

namespace Zara\Container;

use Zara\Core\Support\YamlParser;
use Zara\Core\Support\PhpParser;
use Zara\Container\Config\ConfigCacheManager;
use Zara\Container\Config\ServiceDefinitionProcessor;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Zara\Container\Exceptions\ContainerException;

/**
 * Orchestrates loading and parsing configuration files.
 */
final class ConfigLoader
{
    private YamlParser $yamlParser;
    private PhpParser $phpParser;
    private ConfigCacheManager $cacheManager;
    private ServiceDefinitionProcessor $processor;

    public function __construct(
        private ContainerInterface $container,
        private ServiceRegistry    $registry,
        private ParameterBag       $parameters,
        private LoggerInterface    $logger,
    ) {
        $this->yamlParser   = new YamlParser();
        $this->phpParser    = new PhpParser();
        $this->cacheManager = new ConfigCacheManager();
        $this->processor    = new ServiceDefinitionProcessor($this->container, $this->registry, $this->parameters);
    }

    /**
     * Loads and processes a configuration file (YAML or PHP).
     *
     * @param string $file File path
     * @param DependencyValidator $validator Dependency validator
     * @throws ContainerException If the file does not exist or has an invalid format
     */
    public function loadFromFile(string $file, DependencyValidator $validator): void
    {
        if (!file_exists($file)) {
            throw new ContainerException("Container Error: Configuration file '$file' does not exist");
        }

        $extension = pathinfo($file, PATHINFO_EXTENSION);

        $cacheFile = ($this->parameters->has('container.cache_file') && $this->parameters->get('container.cache_file') !== '')
            ? (string) $this->parameters->get('container.cache_file')
            : 'config/cache-services.php';

        $cacheEnabled = $this->parameters->has('container.cache_enable')
           ? (bool) $this->parameters->get('container.cache_enable')
           : true;

        $configData = $cacheEnabled ? $this->cacheManager->loadFromCache($file, $cacheFile) : null;
        $fromCache = $configData !== null;

        if ($configData === null) {
            $configData = match ($extension) {
                'yaml', 'yml' => $this->yamlParser->parse($file),
                'php'         => $this->phpParser->parse($file),
                default       => throw new ContainerException("Container Error: Unsupported file extension '$extension'. Use 'yaml' or 'php'."),
            };
            if ($cacheEnabled) {
                $this->cacheManager->saveToCache($file, $cacheFile, $configData, filemtime($file));
            }
        }

        $this->logger->debug("Loaded configuration from '$file' (cache: " . ($fromCache ? 'yes' : 'no') . ').');

        $config = $configData['data'] ?? $configData;
        
        // Delegate parameter and service processing to the dedicated processor.
        $this->processor->process($config, $validator, $file);
    }
}

<?php
declare(strict_types=1);

namespace Zara\Container\Config;

use Psr\Container\ContainerInterface;
use Zara\Container\ServiceRegistry;
use Zara\Container\ParameterBag;
use Zara\Container\DependencyValidator;
use Zara\Container\Exceptions\ContainerException;

final class ServiceDefinitionProcessor
{
    private ResourceScanner $scanner;

    public function __construct(
        private ContainerInterface $container,
        private ServiceRegistry $registry,
        private ParameterBag $parameters,
    ) {
        $this->scanner = new ResourceScanner();
    }

    public function process(array $config, DependencyValidator $validator, ?string $configFile = null): void
    {
        if (isset($config['parameters']) && is_array($config['parameters'])) {
            foreach ($config['parameters'] as $id => $value) {
                $this->parameters->bind((string)$id, $value);
            }
        }

        if (!isset($config['services']) || !is_array($config['services'])) {
            return;
        }

        $rawServices = $config['services'];
        $this->validateServiceKeys($rawServices);
        $defaults = [];

        if (isset($rawServices['_defaults']) && is_array($rawServices['_defaults'])) {
            $defaults = $rawServices['_defaults'];
            unset($rawServices['_defaults']);
            $this->registry->setDefaults($defaults);
        }

        $projectRoot = $this->parameters->has('root')
            ? (string) $this->parameters->get('root')
            : getcwd();

        $configDir = $configFile !== null ? dirname($configFile) : null;
        $expandedServices = [];

        // 1. Pass one: process resource/exclude prototypes.
        foreach ($rawServices as $id => $service) {
            if (is_array($service) && isset($service['resource'])) {
                $namespace = (string) $id;
                $resourcePattern = (string) $service['resource'];
                $excludePattern = $service['exclude'] ?? [];

                $discovered = $this->scanner->scan(
                    $namespace,
                    $resourcePattern,
                    $excludePattern,
                    (string) $projectRoot,
                    $configDir
                );

                foreach ($discovered as $fqcn => $discoveredDef) {
                    // Inherit local rules from the block (autowire, public, scope, etc.).
                    $protoDefinition = $service;
                    unset($protoDefinition['resource'], $protoDefinition['exclude']);

                    $expandedServices[$fqcn] = array_merge($protoDefinition, $discoveredDef);
                }
            }
        }

        // 2. Pass two: merge explicit service definitions (they override discovered services).
        foreach ($rawServices as $id => $service) {
            if (is_array($service) && isset($service['resource'])) {
                continue;
            }
            $expandedServices[(string)$id] = $service;
        }

        // 3. Pre-validate dependencies across the expanded service list.
        $validator->preResolveDependencies($expandedServices);

        // 4. Register definitions in the ServiceRegistry.
        foreach ($expandedServices as $id => $service) {
            if (isset($service['alias'])) {
                $this->registry->setAlias((string)$id, (string)$service['alias']);
                continue;
            }

            if ((bool) ($service['synthetic'] ?? false)) {
                $this->registerSynthetic((string)$id, $service, $defaults);
                continue;
            }

            $def = [
                'callable' => $service['class'] ?? $service['callable'] ?? (class_exists((string)$id) ? (string)$id : null),
                'scope'    => $service['scope']    ?? $defaults['scope']    ?? 'prototype',
                'arguments'=> $service['arguments'] ?? [],
                'calls'    => $service['calls']    ?? [],
                'tags'     => $service['tags']     ?? [],
                'lazy'     => (bool)($service['lazy']     ?? $defaults['lazy']     ?? false),
                'preload'  => (bool)($service['preload']  ?? $defaults['preload']  ?? false),
                'autowire' => (bool)($service['autowire']  ?? $defaults['autowire']  ?? true),
                'public'   => (bool)($service['public']    ?? $defaults['public']    ?? true),
            ];

            if (!$def['callable']) {
                throw new ContainerException("Container Error: Service '$id' must have a 'class' or 'callable'.");
            }

            $this->registry->define((string)$id, $def);

            if (!empty($def['tags'])) {
                $this->registry->addTags((string)$id, $def['tags']);
            }

            if ($def['preload'] && $def['scope'] === 'singleton') {
                $this->container->get((string)$id);
            }
        }

        $this->registry->validateAliases();
    }

    private function validateServiceKeys(array $services): void
    {
        $allowed = ['class', 'callable', 'alias', 'synthetic', 'scope', 'arguments', 'calls', 'tags', 'lazy', 'preload', 'autowire', 'public', 'resource', 'exclude'];
        foreach ($services as $id => $service) {
            if ($id === '_defaults' || !is_array($service)) {
                continue;
            }
            $unknown = array_diff(array_keys($service), $allowed);
            if ($unknown !== []) {
                throw new ContainerException("Container Error: Unknown key(s) in definition for '$id': " . implode(', ', $unknown));
            }
        }
    }

    /**
     * Registers a synthetic service declared in services.yaml. A synthetic
     * service is never constructed by the container — it must be provided
     * externally via Container::set() before it is requested — so keys that
     * only make sense for constructible services would silently have no
     * effect if left in the config. Rather than ignoring them, we fail loud:
     * a config author who writes 'class' or 'lazy' next to 'synthetic: true'
     * almost certainly expects it to do something, and it wouldn't.
     */
    private function registerSynthetic(string $id, array $service, array $defaults): void
    {
        $meaningless = array_intersect(
            ['class', 'callable', 'arguments', 'calls', 'lazy', 'autowire', 'scope'],
            array_keys($service)
        );

        if ($meaningless !== []) {
            $keys = implode(', ', array_map(static fn(string $k) => "'$k'", $meaningless));

            throw new ContainerException(
                "Container Error: Service '$id' is declared 'synthetic' but also sets $keys. " .
                "A synthetic service is provided externally via Container::set() and is never " .
                "constructed by the container — these key(s) would silently have no effect."
            );
        }

        if ((bool) ($service['preload'] ?? false)) {
            throw new ContainerException(
                "Container Error: Service '$id' cannot be both 'synthetic' and 'preload' — preloading " .
                "would call Container::get('$id') while configuration is still loading, before " .
                "Container::set() has had a chance to provide the real instance, and would fail " .
                "with SyntheticServiceNotProvidedException."
            );
        }

        $def = [
            'synthetic' => true,
            'tags'      => $service['tags']   ?? [],
            'public'    => (bool) ($service['public'] ?? $defaults['public'] ?? true),
        ];

        $this->registry->define($id, $def);

        if (!empty($def['tags'])) {
            $this->registry->addTags($id, $def['tags']);
        }
    }
}

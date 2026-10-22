<?php
// ===============================================
// File: src/Zara/Container/DependencyValidator.php
// ===============================================


declare(strict_types=1);


namespace Zara\Container;

use Zara\Container\Exceptions\ContainerException;

final class DependencyValidator
{
    public function __construct(private ServiceRegistry $registry) {}


/**
* Early validation of services array (from config) to detect obvious issues.
* Keeps the behavior lenient (no hard failure if class not instantiable).
*/
    public function preResolveDependencies(array $services): void
    {
        foreach ($services as $id => $service) {
        if (is_array($service) && ($service['synthetic'] ?? false)) {
            // Synthetic services are never constructed by the container — they're
            // provided externally via Container::set() — so there's nothing here
            // to reflect on. Skipping explicitly (rather than relying on the
            // absence of 'class'/'callable' below) also means that a config
            // mistakenly combining 'synthetic' with a 'class' key doesn't get
            // caught here with a generic "Invalid class" error: it's ServiceDefinitionProcessor's
            // job to reject that combination with a message that explains why.
            continue;
        }

        $classSpec = $service['class'] ?? $service['callable'] ?? null;
        if (!is_string($classSpec)) {
            continue;
        }
  
        $class = explode('::', $classSpec)[0];
        if (!class_exists($class)) {      
            throw new ContainerException("Container Error: Invalid class '$class' for service '$id'");
        }

        $refl = new \ReflectionClass($class);
        if (!$refl->isInstantiable()) {
           continue; // factories or static builders handled elsewhere
        }


        $ctor = $refl->getConstructor();
        if (!$ctor) {
            continue;
        }

        foreach ($ctor->getParameters() as $param) {
            $type = $param->getType();
            if (!$type || !($type instanceof \ReflectionNamedType)) {
                continue;
            }
            $typeName = $type->getName();
            if ($this->isScalarType($typeName)) {
                continue;
            }
            // If the type is not scalar and not obviously resolvable, we keep lenient as original
            // Optionally: throw here to enforce strict configs
            // if (!$this->registry->hasDefinition($typeName) && !class_exists($typeName)) {
            // throw new ContainerException("Container Error: Service '$typeName' required by '$id' not found in definitions");
            // }
            }
        }
    }

    private function isScalarType(string $type): bool
    {
        return in_array($type, ['int', 'string', 'bool', 'float', 'array'], true);
    }
}
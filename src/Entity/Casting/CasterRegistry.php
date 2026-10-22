<?php
declare(strict_types=1);

namespace Zara\Entity\Casting;

/**
 * Mémoïse une instance par classe de caster, pour éviter de reconstruire
 * un caster à chaque colonne/ligne hydratée. Même esprit que les autres
 * Registry du framework (CircularDependencyGuard, DefinitionRegistry...).
 */
final class CasterRegistry
{
    /** @var array<class-string<ValueCasterInterface>, ValueCasterInterface> */
    private array $instances = [];

    public function get(string $casterClass): ValueCasterInterface
    {
        return $this->instances[$casterClass] ??= new $casterClass();
    }
}
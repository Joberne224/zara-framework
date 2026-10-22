<?php
declare(strict_types=1);

namespace Zara\Entity\Metadata;

use Zara\Entity\Attribute\Column;
use Zara\Entity\Attribute\Id;
use Zara\Entity\Attribute\Table;
use Zara\Entity\Attribute\Version;
use Zara\Entity\Attribute\ManyToMany;
use Zara\Entity\Attribute\ManyToOne;
use Zara\Entity\Attribute\OneToMany;
use Zara\Entity\Relation\ManyToManyMetadata;
use Zara\Entity\Relation\ManyToOneMetadata;
use Zara\Entity\Relation\OneToManyMetadata;

final class MetadataFactory
{
    /** @var array<class-string, EntityMetadata> */
    private array $cache = [];

    public function metadataFor(string $entityClass): EntityMetadata
    {
        return $this->cache[$entityClass] ??= $this->build($entityClass);
    }

    private function build(string $entityClass): EntityMetadata
    {
        $reflection = new \ReflectionClass($entityClass);

        $tableAttr = $reflection->getAttributes(Table::class)[0] ?? null;
        if ($tableAttr === null) {
            throw new \LogicException("$entityClass has no #[Table] attribute.");
        }

        $table = $tableAttr->newInstance()->name;

        $columnToProperty = [];
        $columnCasters = [];
        $relations = [];
        $idColumn = null;
        $idGenerated = true;
        $versionColumn = null;

        foreach ($this->collectProperties($reflection) as $property) {
            $idAttrs = $property->getAttributes(Id::class);
            $versionAttrs = $property->getAttributes(Version::class);
            $columnAttrs = $property->getAttributes(Column::class);

            // #[Id]/#[Version]/#[Column] are standalone and self-sufficient —
            // each one registers its own column on its own. More than one on
            // the same property is never meaningful (which name wins?), and
            // checking attribute presence first, instead of "the first match
            // wins" via the chain of continues below, is what makes this
            // detectable at all: whichever branch ran first would otherwise
            // silently register the property under its own column name and
            // never look at the others.
            if (count($idAttrs) + count($versionAttrs) + count($columnAttrs) > 1) {
                throw new \LogicException(sprintf(
                    '%s::$%s declares more than one of #[Id]/#[Version]/#[Column] — a property can only have one.',
                    $property->getDeclaringClass()->getName(),
                    $property->getName(),
                ));
            }

            if ($idAttrs !== []) {
                /** @var Id $id */
                $id = $idAttrs[0]->newInstance();

                if ($idColumn !== null && $idColumn !== $id->column) {
                    throw new \LogicException(sprintf(
                        '%s declares #[Id] on both "%s" and "%s" — an entity can only have one id column.',
                        $entityClass,
                        $idColumn,
                        $id->column,
                    ));
                }

                $idColumn = $id->column;
                $idGenerated = $id->generated;
                $this->registerColumn($entityClass, $columnToProperty, $id->column, $property);

                continue;
            }

            if ($versionAttrs !== []) {
                /** @var Version $version */
                $version = $versionAttrs[0]->newInstance();
                $versionColumnName = $version->column ?? $property->getName();

                if ($versionColumn !== null && $versionColumn !== $versionColumnName) {
                    throw new \LogicException(sprintf(
                        '%s declares #[Version] on both "%s" and "%s" — an entity can only have one version column.',
                        $entityClass,
                        $versionColumn,
                        $versionColumnName,
                    ));
                }

                $versionColumn = $versionColumnName;
                $this->registerColumn($entityClass, $columnToProperty, $versionColumnName, $property);

                continue;
            }

            if ($columnAttrs !== []) {
                /** @var Column $column */
                $column = $columnAttrs[0]->newInstance();
                $this->registerColumn($entityClass, $columnToProperty, $column->name, $property);

                if ($column->caster !== null) {
                    $columnCasters[$column->name] = $column->caster;
                }

                continue;
            }

            $oneToManyAttrs = $property->getAttributes(OneToMany::class);
            if ($oneToManyAttrs !== []) {
                /** @var OneToMany $oneToMany */
                $oneToMany = $oneToManyAttrs[0]->newInstance();
                $relations[$property->getName()] = new OneToManyMetadata($oneToMany->target, $oneToMany->foreignKey);

                continue;
            }

            $manyToOneAttrs = $property->getAttributes(ManyToOne::class);
            if ($manyToOneAttrs !== []) {
                /** @var ManyToOne $manyToOne */
                $manyToOne = $manyToOneAttrs[0]->newInstance();
                $relations[$property->getName()] = new ManyToOneMetadata($manyToOne->target, $manyToOne->localKey);

                continue;
            }

            $manyToManyAttrs = $property->getAttributes(ManyToMany::class);
            if ($manyToManyAttrs !== []) {
                /** @var ManyToMany $manyToMany */
                $manyToMany = $manyToManyAttrs[0]->newInstance();
                $relations[$property->getName()] = new ManyToManyMetadata(
                    $manyToMany->target,
                    $manyToMany->pivotTable,
                    $manyToMany->pivotOwnerColumn,
                    $manyToMany->pivotTargetColumn,
                );
            }
        }

        if ($idColumn === null) {
            throw new \LogicException("$entityClass has no #[Id] column.");
        }

        $this->assertHydratable(
            $reflection,
            array_merge(
                array_map(static fn (\ReflectionProperty $p): string => $p->getName(), array_values($columnToProperty)),
                array_keys($relations),
            ),
        );

        $immutable = true;
        foreach ($columnToProperty as $property) {
            if (!$property->isReadOnly()) {
                $immutable = false;
                break;
            }
        }

        return new EntityMetadata(
            $table,
            $columnToProperty,
            $idColumn,
            $idGenerated,
            $versionColumn,
            $relations,
            $columnCasters,
            $immutable,
        );
    }

    /**
     * Single choke point for registering a property under a column name —
     * used by #[Id], #[Version] and #[Column] alike, so the same duplicate-
     * column protection applies no matter which attribute declared it.
     *
     * Two ReflectionProperty objects for the same column name are a harmless
     * duplicate ONLY in one specific case: the SAME property name, reflected
     * once via the child class and once via an ancestor's own ReflectionClass
     * (collectProperties() below walks the whole hierarchy), where both are
     * non-private — PHP then resolves both to the exact same underlying
     * storage slot, so registering whichever was seen first is harmless.
     *
     * Anything else reaching here is a genuine conflict, not shadowing:
     *  - Two DIFFERENT property names mapped to the same column name by a
     *    copy-paste mistake — same visibility or not, there is no shared
     *    slot here, just two unrelated properties racing to own one column.
     *  - Either declaration being PRIVATE — a private property is never
     *    shared with another class's declaration (PHP gives each one its
     *    own, separate storage slot even when same-named), so two of them
     *    mapped to one column name silently lose track of one of the slots.
     *
     * @param array<string, \ReflectionProperty> $columnToProperty
     */
    private function registerColumn(
        string $entityClass,
        array &$columnToProperty,
        string $columnName,
        \ReflectionProperty $property,
    ): void {
        if (isset($columnToProperty[$columnName])) {
            $existing = $columnToProperty[$columnName];

            $harmlessShadow = $existing->getName() === $property->getName()
                && !$existing->isPrivate()
                && !$property->isPrivate();

            if (!$harmlessShadow) {
                throw new \LogicException(sprintf(
                    '%s maps both %s::$%s and %s::$%s to the same column "%s" — '
                        . 'each column must come from exactly one property.',
                    $entityClass,
                    $existing->getDeclaringClass()->getName(),
                    $existing->getName(),
                    $property->getDeclaringClass()->getName(),
                    $property->getName(),
                    $columnName,
                ));
            }
        }

        $columnToProperty[$columnName] = $property;
    }

    /**
     * ReflectionClass::getProperties() silently misses one case: a PRIVATE
     * property declared on an ancestor class. PHP treats private properties
     * as belonging strictly to their declaring class rather than being
     * inherited, so $reflection->getProperties() on the child only surfaces
     * public/protected properties from parents plus whatever the child
     * declares itself — a private #[Column] property on a parent class is
     * invisible, and its column is silently never persisted, with no error.
     *
     * Walking the class hierarchy and calling getProperties() on each
     * ancestor's own ReflectionClass surfaces those private properties too.
     * Keyed by declaring-class + name so the same inherited public/protected
     * property encountered at multiple levels of the walk collapses to one
     * entry rather than being processed twice.
     *
     * @param \ReflectionClass<object> $reflection
     * @return \ReflectionProperty[]
     */
    private function collectProperties(\ReflectionClass $reflection): array
    {
        $properties = [];

        for ($class = $reflection; $class !== false; $class = $class->getParentClass()) {
            foreach ($class->getProperties() as $property) {
                $key = $property->getDeclaringClass()->getName() . '::' . $property->getName();
                $properties[$key] ??= $property;
            }
        }

        return array_values($properties);
    }

    /**
     * Entities are hydrated through NAMED constructor arguments. Failing here,
     * once per class, gives an actionable message instead of a generic
     * "Unknown named parameter" at the first hydration.
     *
     * @param \ReflectionClass<object> $reflection
     * @param string[] $propertyNames
     */
    private function assertHydratable(\ReflectionClass $reflection, array $propertyNames): void
    {
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            throw new \LogicException(sprintf(
                '%s has no constructor: mapped properties are hydrated through named constructor arguments.',
                $reflection->getName(),
            ));
        }

        $parameters = [];
        foreach ($constructor->getParameters() as $parameter) {
            if ($parameter->isVariadic()) {
                return;
            }

            $parameters[$parameter->getName()] = true;
        }

        $missing = array_values(array_filter(
            $propertyNames,
            static fn (string $name): bool => !isset($parameters[$name]),
        ));

        if ($missing !== []) {
            throw new \LogicException(sprintf(
                '%s: the constructor has no parameter named "%s". Mapped properties are hydrated through '
                    . 'named constructor arguments, so each one needs a constructor parameter with the same name.',
                $reflection->getName(),
                implode('", "', $missing),
            ));
        }
    }
}
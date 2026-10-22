<?php

declare(strict_types=1);

namespace Zara\Model;

use Zara\Entity\Attribute\Column;
use Zara\Entity\Attribute\Id;
use Zara\Entity\Attribute\Version;
use Zara\Entity\Casting\ValueCasterInterface;

/**
 * Properties are intentionally public + readonly, not private with
 * getters/setters. `readonly` already provides the guarantee a getter would
 * exist for — external code cannot mutate the value — so a private property
 * behind a getter would add boilerplate without adding any protection.
 * Making these properties private would turn the entity into a plain data
 * bag requiring a getter/setter per field for zero additional safety.
 * Do not "encapsulate" this further.
 */
abstract class AbstractModel
{   
    /**
     * @var array<class-string, array<string, array{property: \ReflectionProperty, caster: class-string<ValueCasterInterface>|null}>>
     */
    private static array $columnMapCache = [];

    /** @var array<class-string<ValueCasterInterface>, ValueCasterInterface> */
    private static array $casterCache = [];

    public function __construct(
        #[Id('id')]
        public readonly ?int $id = null,
    ) {
    }

    public function isNew(): bool
    {
        return $this->id === null;
    }

    public function equals(self $other): bool
    {
        return $this->id !== null
            && $this::class === $other::class
            && $this->id === $other->id;
    }

    /**
     * Default reflection-based implementation. A child entity that declares
     * its own toArray() keeps full control (this method is simply never
     * called for it) — this exists purely to spare boilerplate for entities
     * that just mirror their #[Column] mapping with no extra logic.
     */
    public function toArray(): array
    {
        $data = [];

        foreach (self::columnMap(static::class) as $column => $meta) {
            $value = $meta['property']->getValue($this);

            $data[$column] = $meta['caster'] !== null
                ? self::caster($meta['caster'])->extract($value)
                : $value;
        }

        return $data;
    }

    /**
     * @return array<string, array{property: \ReflectionProperty, caster: class-string<ValueCasterInterface>|null}>
     */
    private static function columnMap(string $class): array
    {
        if (isset(self::$columnMapCache[$class])) {
            return self::$columnMapCache[$class];
        }

        $map = [];
        foreach ((new \ReflectionClass($class))->getProperties() as $property) {
            $mapping = self::mappingOf($property);
            if ($mapping === null) {
                continue;
            }

            $map[$mapping['column']] = ['property' => $property, 'caster' => $mapping['caster']];
        }

        return self::$columnMapCache[$class] = $map;
    }

    /**
     * Resolves the column a property maps to. #[Id] and #[Version] are
     * standalone declarations — they replace #[Column], they do not add to it —
     * and neither supports a caster. Keep these rules in sync with
     * MetadataFactory: toArray() must mirror exactly what the mapper persists.
     *
     * @return array{column: string, caster: class-string<ValueCasterInterface>|null}|null
     */
    private static function mappingOf(\ReflectionProperty $property): ?array
    {
        $column = $property->getAttributes(Column::class)[0] ?? null;
        $id = $property->getAttributes(Id::class)[0] ?? null;
        $version = $property->getAttributes(Version::class)[0] ?? null;

        if (count(array_filter([$column, $id, $version])) > 1) {
            throw new \LogicException(sprintf(
                '%s::$%s carries more than one of #[Column], #[Id] and #[Version]; '
                    . 'each of them already maps the property to a column.',
                $property->getDeclaringClass()->getName(),
                $property->getName(),
            ));
        }

        if ($id !== null) {
            return ['column' => $id->newInstance()->column, 'caster' => null];
        }

        if ($version !== null) {
            return ['column' => $version->newInstance()->column ?? $property->getName(), 'caster' => null];
        }

        if ($column !== null) {
            $instance = $column->newInstance();

            return ['column' => $instance->name, 'caster' => $instance->caster];
        }

        return null;
    }

    private static function caster(string $casterClass): ValueCasterInterface
    {
        return self::$casterCache[$casterClass] ??= new $casterClass();
    }
}
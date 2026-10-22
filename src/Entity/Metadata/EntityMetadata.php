<?php
// Zara\Entity\Metadata\EntityMetadata.php
declare(strict_types=1);

namespace Zara\Entity\Metadata;

use Zara\Entity\Relation\RelationMetadataInterface;

final class EntityMetadata
{
    /**
     * @param array<string, \ReflectionProperty> $columnToProperty
     * @param bool $idGenerated true when the database generates the id on
     *        insert (AUTO_INCREMENT / SERIAL / ROWID) — EntityPersister must
     *        then omit it from the INSERT and read it back via
     *        lastInsertId(). false means the application supplies it itself
     *        (e.g. a UUID assigned before construction): the supplied value
     *        is kept in the INSERT and used as-is, lastInsertId() is never
     *        consulted for it.
     * @param string|null $versionColumn Optimistic-locking column, from
     *        #[Version]. null means the entity has none: EntityPersister
     *        falls back to plain last-write-wins on every update.
     * @param array<string, class-string<\Zara\Entity\Casting\ValueCasterInterface>> $columnCasters column => caster, only for columns that declare one
     * @param array<string, RelationMetadataInterface> $relations
     * @param bool $immutable true when every mapped column property is readonly, i.e. the
     *        entity can never be modified in place and does not need dirty checking
     */
    public function __construct(
        public readonly string $table,
        public readonly array $columnToProperty,
        public readonly string $idColumn,
        public readonly bool $idGenerated = true,
        public readonly ?string $versionColumn = null,
        public readonly array $relations = [],
        public readonly array $columnCasters = [],
        public readonly bool $immutable = false,
    ) {
    }

    public function idProperty(): \ReflectionProperty
    {
        return $this->columnToProperty[$this->idColumn];
    }

    /**
     * @return array<string, \ReflectionProperty>
     */
    public function columnsWithoutId(): array
    {
        return array_diff_key($this->columnToProperty, [$this->idColumn => null]);
    }

    public function casterFor(string $column): ?string
    {
        return $this->columnCasters[$column] ?? null;
    }
}
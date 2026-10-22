<?php
declare(strict_types=1);

namespace Zara\Entity\Attribute;

/**
 * Marks the identity column of an entity, standalone — a property carrying
 * #[Id] needs no separate #[Column].
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Id
{
    /**
     * @param string $column Column name. Required — unlike Version, an id
     *        column is referenced constantly (WHERE clauses, lastInsertId
     *        normalization) and should never be left to an implicit
     *        property-name fallback.
     * @param bool $generated Whether the database generates the value on
     *        insert (AUTO_INCREMENT / SERIAL / ROWID). false means the
     *        application supplies it itself (e.g. a UUID assigned before
     *        the entity is constructed) — EntityPersister::insert() must
     *        then keep the id in the INSERT and never consult
     *        lastInsertId() for it.
     */
    public function __construct(
        public readonly string $column,
        public readonly bool $generated = true,
    ) {
    }
}
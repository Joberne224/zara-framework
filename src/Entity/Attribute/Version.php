<?php
declare(strict_types=1);

namespace Zara\Entity\Attribute;

/**
 * Marks the optimistic-locking version column, standalone — a property
 * carrying #[Version] needs no separate #[Column]. Replaces the previous
 * convention of detecting optimistic locking by the magic column name
 * "version": any entity happening to have an unrelated "version" column
 * (a product's semver string, say) would have silently been treated as
 * optimistically locked. An explicit attribute removes that ambiguity.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Version
{
    /**
     * @param string|null $column Column name. Defaults to the property's own
     *        name (e.g. a property named $version maps to column "version"),
     *        matching the convention this attribute replaces — pass it
     *        explicitly only when the column is named differently.
     */
    public function __construct(
        public readonly ?string $column = null,
    ) {
    }
}
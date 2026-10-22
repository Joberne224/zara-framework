<?php
//File: zara-framework/src/Entity/Relation/LazyRelation.php
declare(strict_types=1);

namespace Zara\Entity\Relation;

/**
 * Lazy-loading container compatible with readonly entities: the property
 * itself stays readonly (only ONE LazyRelation instance is ever assigned),
 * only internal mutation (resolution + caching) happens inside this object,
 * which is not a domain model.
 */
final class LazyRelation
{
    private bool $resolved;
    private mixed $value;
    private readonly ?\Closure $resolver;

    private function __construct(?\Closure $resolver, bool $resolved, mixed $value)
    {
        $this->resolver = $resolver;
        $this->resolved = $resolved;
        $this->value = $value;
    }

    public static function lazy(\Closure $resolver): self
    {
        return new self($resolver, false, null);
    }

    public static function resolved(mixed $value): self
    {
        return new self(null, true, $value);
    }

    public function get(): mixed
    {
        if (!$this->resolved) {
            $this->value = ($this->resolver)();
            $this->resolved = true;
        }

        return $this->value;
    }

    public function isLoaded(): bool
    {
        return $this->resolved;
    }

    public function resolveWith(mixed $value): void
    {
        if ($this->resolved) {
            return; // Never overwrite an already-resolved relation
        }

        $this->value = $value;
        $this->resolved = true;
    }

    /**
     * Discards any cached value so the next get() call re-resolves —
     * needed after a pivot write (attach/detach/sync on a #[ManyToMany]
     * relation) makes an already-resolved collection stale.
     *
     * Only meaningful for a relation built via lazy(): one built via
     * resolved() (attached through eager-loading preload()) has no
     * resolver to fall back on and cannot be invalidated — calling this on
     * one is a no-op, since there is nothing left to re-fetch from. A
     * caller that eager-loaded the relation and then writes to its pivot
     * must re-fetch the entity itself to see the change.
     */
    public function invalidate(): void
    {
        if ($this->resolver === null) {
            return;
        }

        $this->resolved = false;
        $this->value = null;
    }
}
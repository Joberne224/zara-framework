<?php
declare(strict_types=1);

namespace Zara\Entity;

use Zara\Entity\Casting\CasterRegistry;
use Zara\Entity\Metadata\MetadataFactory;
use Zara\Entity\Relation\Interfaces\RelationLoaderInterface;
use Zara\Entity\Interfaces\HydratorInterface;

final class AttributeHydrator implements HydratorInterface
{
    private ?RelationLoaderInterface $relationLoader = null;

    public function __construct(
        private readonly MetadataFactory $metadataFactory,
        private readonly ScalarCaster   $caster,
        private readonly CasterRegistry $casterRegistry,
    ) {
    }

    public function setRelationLoader(RelationLoaderInterface $relationLoader): void
    {
        $this->relationLoader = $relationLoader;
    }

    public function hydrate(string $entityClass, array $data, array $preloadedRelations = []): object
    {
        $metadata = $this->metadataFactory->metadataFor($entityClass);
        $reflection = new \ReflectionClass($entityClass);

        $constructorArgs = [];
        foreach ($metadata->columnToProperty as $column => $property) {
            if (!array_key_exists($column, $data)) {
                continue;
            }

            $casterClass = $metadata->casterFor($column);

            $constructorArgs[$property->getName()] = $casterClass !== null
                ? $this->casterRegistry->get($casterClass)->hydrate($data[$column])
                : $this->caster->castValue($data[$column], $property);
        }

        if ($metadata->relations !== [] && $this->relationLoader === null) {
            throw new \LogicException(
                "$entityClass declares relations but AttributeHydrator::setRelationLoader() was never called."
            );
        }

        $ownerId = $data[$metadata->idColumn] ?? null;

        foreach ($metadata->relations as $propertyName => $relation) {
            $constructorArgs[$propertyName] = $relation->buildLazyRelation(
                $this->relationLoader,
                $data,
                $ownerId,
                $preloadedRelations[$propertyName] ?? null,
            );
        }

        return $reflection->newInstance(...$constructorArgs);
    }

    public function extract(object $entity): array
    {
        $metadata = $this->metadataFactory->metadataFor($entity::class);

        $data = [];
        foreach ($metadata->columnToProperty as $column => $property) {
            $value = $property->getValue($entity);
            $casterClass = $metadata->casterFor($column);

            $data[$column] = $casterClass !== null
                ? $this->casterRegistry->get($casterClass)->extract($value)
                : $value;
        }

        return $data;
    }

    public function attachPreloadedRelations(object $entity, array $preloadedRelations): void
    {
        if ($preloadedRelations === []) {
            return;
        }

        $metadata = $this->metadataFactory->metadataFor($entity::class);

        if ($metadata->relations === []) {
            return;
        }

        if ($this->relationLoader === null) {
            $entityClass = $entity::class;

            throw new \LogicException(
                "{$entityClass} declares relations but AttributeHydrator::setRelationLoader() was never called."
            );
        }

        $reflection = new \ReflectionClass($entity);
        $ownerRow = $this->extract($entity);
        $ownerId = $ownerRow[$metadata->idColumn] ?? null;

        foreach ($preloadedRelations as $propertyName => $preloadedForRelation) {
            $relation = $metadata->relations[$propertyName] ?? null;

            if ($relation === null || $preloadedForRelation === null) {
                continue;
            }

            $property = $reflection->getProperty($propertyName);

            /** @var \Zara\Entity\Relation\LazyRelation $existing */
            $existing = $property->getValue($entity);

            if ($existing->isLoaded()) {
                continue; // Already resolved (used or preloaded earlier) — never overwritten
            }

            $fresh = $relation->buildLazyRelation($this->relationLoader, $ownerRow, $ownerId, $preloadedForRelation);
            $existing->resolveWith($fresh->get());
        }
    }

    public function invalidateRelation(object $entity, string $propertyName): void
    {
        $reflection = new \ReflectionClass($entity);
        $property = $reflection->getProperty($propertyName);

        /** @var \Zara\Entity\Relation\LazyRelation $lazyRelation */
        $lazyRelation = $property->getValue($entity);
        $lazyRelation->invalidate();
    }
}
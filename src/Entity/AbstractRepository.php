<?php
declare(strict_types=1);

namespace Zara\Entity;

use Zara\Database\DatabaseInterface;
use Zara\Entity\Interfaces\EntityManagerInterface;
use Zara\Database\Query\QueryBuilder;
use Zara\Entity\Metadata\MetadataFactory;

/**
 * @template T of object
 */
abstract class AbstractRepository
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly DatabaseInterface      $db,
        protected readonly MetadataFactory        $metadataFactory,
    ) {
    }

    /**
     * @return class-string<T>
     */
    abstract protected function entityClass(): string;

    /**
     * @param string[] $with
     * @return T|null
     */
    public function find(int|string $id, array $with = []): ?object
    {
        return $this->entityManager->find($this->entityClass(), $id, $with);
    }

    /**
     * @param string[] $with
     * @return T[]
     */
    public function findAll(array $with = []): array
    {
        return $this->entityManager->findAll($this->entityClass(), $with);
    }

    /**
     * @param array<int, int|string> $ids
     * @param string[] $with Relation names to eager-load across the batch
     * @return T[]
     */
    public function findMany(array $ids, array $with = []): array
    {
        return $this->entityManager->findMany($this->entityClass(), $ids, $with);
    }

    /**
     * @param T $entity
     * @return T
     */
    public function save(object $entity): object
    {
        return $this->entityManager->save($entity);
    }

    /**
     * @param T $entity
     */
    public function delete(object $entity): void
    {
        $this->entityManager->delete($entity);
    }

    protected function query(): QueryBuilder
    {
        return QueryBuilder::table($this->db, $this->tableName());
    }

    protected function tableName(): string
    {
        return $this->metadataFactory->metadataFor($this->entityClass())->table;
    }

    /**
     * Hydrates a single row from a custom business query.
     *
     * @param array<string, mixed> $row
     * @param string[] $with
     * @return T
     */
    protected function hydrate(array $row, array $with = []): object
    {
        return $this->hydrateAll([$row], $with)[0];
    }

    /**
     * Hydrates a batch of rows from a custom business query (e.g., findActive()).
     *
     * @param array<int, array<string, mixed>> $rows
     * @param string[] $with
     * @return T[]
     */
    protected function hydrateAll(array $rows, array $with = []): array
    {
        if ($rows === []) {
            return [];
        }

        $metadata = $this->metadataFactory->metadataFor($this->entityClass());
        $preloaded = $with === []
            ? []
            : $this->entityManager->preloadRelations($metadata, $rows, $with);

        return array_map(
            function (array $row) use ($preloaded): object {
                return $this->entityManager->hydrateAndTrack($this->entityClass(), $row, $preloaded);
            },
            $rows,
        );
    }
}
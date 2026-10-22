<?php
declare(strict_types=1);

namespace Zara\Entity;

use Zara\Entity\Metadata\EntityMetadata;

final class ScalarCaster
{
    public function castValue(mixed $value, \ReflectionProperty $property): mixed
    {
        if ($value === null) {
            return null;
        }

        $type = $property->getType();
        if (!$type instanceof \ReflectionNamedType || !$type->isBuiltin()) {
            return $value;
        }

        return match ($type->getName()) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => (bool) $value,
            'string' => (string) $value,
            default => $value,
        };
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function castRow(array $row, EntityMetadata $metadata): array
    {
        $cast = [];

        foreach ($metadata->columnToProperty as $column => $property) {
            if (array_key_exists($column, $row)) {
                $cast[$column] = $this->castValue($row[$column], $property);
            }
        }

        return $cast;
    }
}
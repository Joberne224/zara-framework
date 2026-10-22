<?php
declare(strict_types=1);

namespace Zara\Entity\Casting;

interface ValueCasterInterface
{
    /** Scalaire brut (ligne SQL) -> valeur de domaine (VO). */
    public function hydrate(mixed $rawValue): mixed;

    /** Valeur de domaine (VO) -> scalaire prêt pour QueryBuilder/PDO. */
    public function extract(mixed $value): mixed;
}
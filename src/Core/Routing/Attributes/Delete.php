<?php

declare(strict_types=1);

namespace Zara\Core\Routing\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Delete extends Route
{
    public function __construct(
        string $path = '',
        ?string $name = null,
        array $middlewares = [],
        array $constraints = []
    ) {
        parent::__construct($path, ['DELETE'], $name, $middlewares, $constraints);
    }
}

<?php
declare(strict_types=1);

namespace Zara\Core\Routing;

/**
 * Représente une action de contrôleur résolue et validée.
 */
final class ControllerAction
{
    public function __construct(
        public readonly string $class,
        public readonly string $method,
    ) {}
}
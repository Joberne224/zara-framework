<?php

declare(strict_types=1);

namespace Zara\Core\Support\Interfaces;

use Closure;

/**
 * Contract for building sequential pipeline execution stacks.
 */
interface PipelineInterface
{
    /**
     * Sets the object to be sent through the pipeline.
     */
    public function send(mixed $passable): static;

    /**
     * Sets the list of stages (pipes/middlewares) to pass through.
     *
     * @param list<string|Closure|object> $stages
     */
    public function through(array $stages): static;

    /**
     * Executes the pipeline towards its final destination handler.
     */
    public function then(Closure|callable $destination): mixed;
}
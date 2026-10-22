<?php
declare(strict_types=1);

namespace Zara\Container\Exceptions;

use ReflectionFunction;
use ReflectionMethod;
use ReflectionParameter;

final class ParameterResolutionException extends ContainerException
{
    public function __construct(
        ReflectionMethod|ReflectionFunction $reflection,
        ?ReflectionParameter $parameter,
        string $reason,
        ?string $suggestion = null,
    ) {
        $target = $reflection instanceof ReflectionMethod
            ? $reflection->getDeclaringClass()->getName() . '::' . $reflection->getName() . '()'
            : ($reflection->getName() ?: 'closure');

        $paramLabel = $parameter !== null ? "\${$parameter->getName()}" : 'one or more parameters';

        $message = "Container Error: Cannot resolve $paramLabel for $target. $reason";
        if ($suggestion !== null) {
            $message .= " $suggestion";
        }

        parent::__construct($message);
    }

    /**
     * Wraps a generic ContainerException raised deep inside ParameterResolver
     * (which has no ReflectionParameter reference, only a rendered message)
     * so that MethodInjector can keep exposing the richer exception type its
     * callers (Dispatcher/ControllerFactory) already expect, instead of
     * leaking ContainerException where ParameterResolutionException was the
     * established contract.
     */
    public static function fromContainerException(
        ReflectionMethod|ReflectionFunction $reflection,
        ContainerException $previous,
    ): self {
        $exception = new self($reflection, null, $previous->getMessage());
        $exception->previous = $previous; // keep the original as prior context, if the base class exposes it

        return $exception;
    }
}
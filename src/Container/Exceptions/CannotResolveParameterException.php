<?php

declare(strict_types=1);
namespace Zara\Container\Exceptions;

/**
 * Exception thrown when a parameter cannot be resolved in the container.
 * This typically occurs when the parameter is not defined or cannot be instantiated.
 * @package App\Container\Exceptions
 * @license MIT
 * @author Bedel Ngambé
 * @version 1.0.0
 * @since 1.0.0
 * This exception is used to indicate that the container was unable to resolve a parameter
 * that was requested, either because it does not exist or cannot be instantiated.
 * It is a runtime exception that should be caught and handled appropriately
 * in the application logic.
 * @example
 * try {
 *   $value = $container->get('some.parameter');
 * } catch (CannotResolveParameterException $e) {
 *   // Handle the exception, e.g., log it or provide a fallback value
 *  echo "Error: " . $e->getMessage();
 * }
 * @see \App\Container\Container
 * @see \App\Container\Container::get()
 * @see \App\Container\Container::has()
 * @see \App\Container\Container::set()
 */
class CannotResolveParameterException extends \Exception 
{
    // TODO: Implement custom logic if needed
}

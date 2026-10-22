<?php
declare(strict_types=1);

namespace Zara\Core\Routing;

/**
 * Contract representing a resolved and validated controller action.
 */
interface ControllerActionInterface
{
    /**
     * Returns the fully qualified controller class name.
     */
    public function getClass(): string;

    /**
     * Returns the controller method name to be executed.
     */
    public function getMethod(): string;
}
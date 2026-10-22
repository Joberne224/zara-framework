<?php
declare(strict_types=1);

namespace Zara\Core\Debug\Profiler\Support;

enum ClassLoadSource: string
{
    case Autoload = 'autoload';               // referenced (new/instanceof/static call), never seen by the container
    case ContainerFresh = 'container_fresh';   // built by ServiceFactory::createInstance()
    case ContainerReused = 'container_reused'; // singleton instance reused from ServiceRegistry
}
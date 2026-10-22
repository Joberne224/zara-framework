<?php

declare(strict_types=1);

namespace Zara\Core\Routing\Loader;

use InvalidArgumentException;
use Zara\Core\Routing\Router;
use Zara\Core\Support\YamlParser;

/**
 * Resolves appropriate loader strategy based on file extension.
 */
final class DelegatingRouteLoader implements RouteLoaderInterface
{
    /** @var list<RouteLoaderInterface> */
    private array $loaders;

    /**
     * @param list<RouteLoaderInterface>|null $loaders
     */
    public function __construct(
        private readonly Router $router,
        ?array $loaders = null
    ) {
        $this->loaders = $loaders ?? [
            new PhpRouteLoader($this->router),
            new YamlRouteLoader($this->router, new YamlParser()),
        ];
    }

    public function load(string $filePath): void
    {
        foreach ($this->loaders as $loader) {
            if ($loader->supports($filePath)) {
                $loader->load($filePath);

                return;
            }
        }

        throw new InvalidArgumentException(
            sprintf("No route loader supports file '%s'. Supported extensions: .php, .yaml, .yml.", $filePath)
        );
    }

    public function supports(string $filePath): bool
    {
        foreach ($this->loaders as $loader) {
            if ($loader->supports($filePath)) {
                return true;
            }
        }

        return false;
    }

    public function addLoader(RouteLoaderInterface $loader): void
    {
        $this->loaders[] = $loader;
    }
}
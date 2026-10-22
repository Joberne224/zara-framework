<?php

declare(strict_types=1);

namespace Zara\View\Engine;

use Psr\Log\LoggerInterface;

use Zara\Core\Routing\Interfaces\RouterInterface;

/**
 * Groups the configuration options used to construct TemplateEngine.
 */
final class TemplateEngineConfig
{
    public function __construct(
        public readonly string           $basePath,
        public readonly string           $cachePath,
        public readonly string           $projectRoot,
        public readonly ?RouterInterface   $router = null,
        public readonly ?string          $baseUrl = null,
        public readonly array            $allowedExtensions = ['html'],
        public readonly bool             $debug = false,
        public readonly ?LoggerInterface $logger = null,
        public readonly bool             $restrictTemplateMethodCalls = true,
    ) {}
}

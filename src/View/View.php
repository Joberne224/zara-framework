<?php
declare(strict_types=1);

namespace Zara\View;

use Throwable;
use Zara\Core\Http\HttpStatusCode;
use Zara\Core\Http\Interfaces\ResponseFactoryInterface;
use Zara\Core\Http\Interfaces\ResponseInterface;
use Zara\View\Interfaces\TemplateEngineInterface;
use Zara\View\Interfaces\ViewInterface;
use Zara\View\Exceptions\ViewRenderException;

/**
 * Encapsulates rendered template output into HTTP Response objects with execution safeguards.
 */
final class View implements ViewInterface
{
    public function __construct(
        private readonly TemplateEngineInterface  $templateEngine,
        private readonly ResponseFactoryInterface $responseFactory
    ) {}

    public function render(
        string $template,
        array  $data = [],
        int    $statusCode = HttpStatusCode::OK
    ): ResponseInterface {
        // Capture initial buffer level to detect leakages if engine fails midway
        $initialObLevel = ob_get_level();

        try {
            $content = $this->templateEngine->render($template, $data);

            return $this->responseFactory->html($content, $statusCode);
        } catch (Throwable $e) {
            // Restore initial output buffer level to prevent output leakage
            while (ob_get_level() > $initialObLevel) {
                ob_end_clean();
            }

            if ($e instanceof ViewRenderException) {
                throw $e;
            }

            throw new ViewRenderException(
                sprintf("An error occurred while rendering template '%s': %s", $template, $e->getMessage()),
                $statusCode,
                $e
            );
        }
    }
}
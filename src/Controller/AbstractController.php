<?php
declare(strict_types=1);

namespace Zara\Controller;

use Zara\Core\Http\HttpStatusCode;
use Zara\Core\Http\JsonResponse;
use Zara\Core\Http\RedirectResponse;
use Zara\Core\Http\Interfaces\ResponseInterface;
use Zara\Core\Routing\Interfaces\RouterInterface;
use Zara\View\Interfaces\ViewInterface;

abstract class AbstractController 
{
    /**
     * @var ViewInterface View object
     */
    private readonly ViewInterface $view;

    private readonly RouterInterface $router;

    public function __construct(ViewInterface $view, RouterInterface $router)
    {
        $this->view = $view;
        $this->router = $router;
    }

    protected function url(string $routeName, array $parameters = []): string 
    {
        return $this->router->route($routeName, $parameters);
    }
    final protected function render(
        string $template,
        array $data = [],
        int $statusCode = HttpStatusCode::OK
    ): ResponseInterface
    {
        return $this->view->render($template, $data, $statusCode);
    }
    final protected function redirect(
        string $url,
        int $statusCode = HttpStatusCode::FOUND
    ): ResponseInterface
    {
        if (
            !str_starts_with($url, '/')
            || str_starts_with($url, '//')
            || str_starts_with($url, '/\\')
            || str_contains($url, '\\')
        ) {
            throw new \InvalidArgumentException('Controller redirects must use a local absolute path.');
        }

        if (!in_array($statusCode, [301, 302, 303, 307, 308], true)) {
            throw new \InvalidArgumentException('Redirect status code must be a 3xx redirect status.');
        }

        return new RedirectResponse($url, $statusCode);
    }
    final protected function json(array $data): ResponseInterface
    {
        return new JsonResponse($data);
    }

    final protected function error(
        string $template,
        string $message, 
        int $statusCode = HttpStatusCode::INTERNAL_SERVER_ERROR, 
        bool $json= false
    ): ResponseInterface {
        if ($json) {
            return new JsonResponse(['message' => $message], $statusCode);
        }

        return $this->render(
            $template, 
            ['message' => $message, 'status' => $statusCode],
            $statusCode
        );
    }   
}

<?php
declare(strict_types=1);

namespace Zara\Core\Http;

use Zara\Core\Http\Interfaces\ResponseInterface;

final class RedirectResponse implements ResponseInterface
{
    use ResponseTrait;

    private ResponseInterface $response;

    public function __construct(string $url, int $statusCode = HttpStatusCode::FOUND)
    {
        $this->response = new Response('', $statusCode, ['Location' => $url]);
    }

    protected function response(): ResponseInterface
    {
        return $this->response;
    }

    protected function withResponseInstance(ResponseInterface $response): static
    {
        $clone = clone $this;
        $clone->response = $response;
        return $clone;
    }
}
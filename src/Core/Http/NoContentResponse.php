<?php

declare(strict_types=1);

namespace Zara\Core\Http;

use Zara\Core\Http\Interfaces\ResponseInterface;

final class NoContentResponse implements ResponseInterface
{
    use ResponseTrait;

    private ResponseInterface $response;

    public function __construct()
    {
        $this->response = new Response('', HttpStatusCode::NO_CONTENT);
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
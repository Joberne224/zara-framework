<?php
declare(strict_types=1);

namespace Zara\Core\Http;

use Zara\Core\Http\Interfaces\ResponseInterface;

final class JsonResponse implements ResponseInterface
{
    use ResponseTrait;

    private Response $response;

    public function __construct(mixed $data, int $statusCode = HttpStatusCode::OK, array $headers = [])
    {
        $headers['Content-Type'] = $headers['Content-Type'] ?? 'application/json';
        $this->response = new Response(json_encode($data, JSON_THROW_ON_ERROR), $statusCode, $headers);
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

    /**
     * Returns a new JsonResponse with different data — replaces the old
     * mutable setData(). Call sites must reassign: $r = $r->withData(...).
     */
    public function withData(mixed $data): static
    {
        return $this->withResponseInstance(
            $this->response->withBody(json_encode($data, JSON_THROW_ON_ERROR))
        );
    }

    public static function error(string $message, int $statusCode = HttpStatusCode::BAD_REQUEST): self
    {
        return new self(['error' => $message], $statusCode);
    }
}
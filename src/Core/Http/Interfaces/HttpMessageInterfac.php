<?php

declare(strict_types=1);

namespace Zara\Core\Http\Interfaces;

/**
 * Contrat commun à tout message HTTP sortant.
 *
 * Ne contient que ce qui est partagé entre une réponse "brute" (ResponseInterface)
 * et une réponse JSON (JsonResponseInterface) : le corps, le code de statut,
 * les en-têtes, les cookies et l'envoi au client.
 *
 * Équivalent maison de Psr\Http\Message\MessageInterface, renommé pour éviter
 * toute confusion si PSR-7 est utilisé ailleurs dans le projet.
 */
interface HttpMessageInterface
{
    public function setContent(string $content): self;

    public function getContent(): string;

    public function getBody(): string;

    public function setBody(string $content): self;

    public function setStatusCode(int $statusCode): self;

    public function getStatusCode(): int;

    public function setHeader(string $name, string $value): self;

    public function getHeader(string $name): ?string;

    public function removeHeader(string $name): self;

    public function getHeaders(): array;

    public function setCookie(
        string $name,
        string $value,
        int $expire = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = false,
        bool $httpOnly = true
    ): self;

    public function send(): void;
}
<?php

declare(strict_types=1);

namespace Zara\Core\LiveServer;

use Closure;
use Zara\Core\Http\Request;
use Zara\Core\Http\Response;

/**
 * Middleware Zara : intercepte les réponses HTML et y injecte
 * le script de live reload via LiveReloadInjector.
 *
 * À enregistrer uniquement en environnement de développement.
 */
final class LiveReloadMiddleware
{
    public function __construct(
        private readonly LiveReloadInjector $injector
    ) {}

    /**
     * Traite la requête et injecte le script si la réponse est du HTML.
     * Utilise le pattern classique des pipelines de middlewares (Request, Closure).
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (!$this->isHtmlResponse($response)) {
            return $response;
        }

        $original = $response->getBody();
        $injected = $this->injector->inject($original);

        // Utilisation de la mutabilité fluide de l'objet Response de Zara
        return $response
            ->setBody($injected)
            ->setHeader('Content-Length', (string) strlen($injected));
    }

    /**
     * Vérifie si la réponse contient du contenu HTML.
     */
    private function isHtmlResponse(Response $response): bool
    {
        $contentType = $response->getHeader('Content-Type') ?? '';
        
        return str_contains($contentType, 'text/html');
    }
}
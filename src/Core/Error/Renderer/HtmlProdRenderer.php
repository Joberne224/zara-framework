<?php
declare(strict_types=1);

namespace Zara\Core\Error\Renderer;

use Zara\Core\Error\HttpException\HttpException;

/**
 * Renderer HTML pour la production.
 *
 * Affiche une page minimaliste sans aucun détail technique.
 * Le code HTTP et un message générique sont les seules informations exposées.
 */
final class HtmlProdRenderer implements RendererInterface
{
    public function render(\Throwable $e): string
    {
        $code    = $e instanceof HttpException ? $e->getStatusCode() : 500;
        $title   = $e instanceof HttpException ? $e->getStatusMessage() : 'Internal Server Error';
        $message = $this->safeMessage($e, $code);

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>{$code} — {$title}</title>
            <style>
                * { box-sizing: border-box; margin: 0; padding: 0; }
                body {
                    font-family: 'Segoe UI', system-ui, sans-serif;
                    background: #0f0f13;
                    color: #e2e8f0;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    min-height: 100vh;
                }
                .card {
                    text-align: center;
                    padding: 3rem 4rem;
                    max-width: 480px;
                }
                .code {
                    font-size: 6rem;
                    font-weight: 800;
                    color: #e53e3e;
                    line-height: 1;
                    margin-bottom: 1rem;
                    letter-spacing: -.05em;
                }
                .title {
                    font-size: 1.5rem;
                    font-weight: 600;
                    margin-bottom: 1rem;
                    color: #fff;
                }
                .message {
                    color: #718096;
                    font-size: .95rem;
                    line-height: 1.7;
                    margin-bottom: 2rem;
                }
                .back {
                    display: inline-block;
                    background: #2d3748;
                    color: #e2e8f0;
                    padding: .6rem 1.5rem;
                    border-radius: 6px;
                    text-decoration: none;
                    font-size: .85rem;
                    transition: background .2s;
                }
                .back:hover { background: #4a5568; }
                .brand { margin-top: 3rem; color: #2d3748; font-size: .7rem; letter-spacing: .1em; text-transform: uppercase; }
            </style>
        </head>
        <body>
            <div class="card">
                <div class="code">{$code}</div>
                <div class="title">{$title}</div>
                <p class="message">{$message}</p>
                <a href="/" class="back">← Back to home</a>
                <div class="brand">Zara Framework</div>
            </div>
        </body>
        </html>
        HTML;
    }

    /**
     * Pour les 4xx on peut exposer le message de l'exception (contrôlé).
     * Pour les 5xx on n'expose rien — un message technique ne doit jamais
     * fuiter en production.
     */
    private function safeMessage(\Throwable $e, int $code): string
    {
        if ($e instanceof HttpException && $code < 500 && $e->getMessage()) {
            return htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return match (true) {
            $code === 404 => 'The page you are looking for could not be found.',
            $code === 403 => 'You do not have permission to access this resource.',
            $code === 405 => 'The request method is not supported for this resource.',
            $code >= 500  => 'Something went wrong on our end. Please try again later.',
            default       => 'An unexpected error occurred.',
        };
    }
}
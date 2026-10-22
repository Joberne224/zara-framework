<?php
declare(strict_types=1);

namespace Zara\View\Runtime;

use Zara\View\RawHtml;

/**
 * XSS protection applied at output time.
 * HTML-context escaping only: safe for element content and quoted
 * attributes. URL, JS and CSS contexts require an explicit filter
 * (safe_url, to_json).
 */
final class Escaper
{
    /**
     * Échappe une valeur avant sa sortie HTML.
     * Ignore les instances de RawHtml pour permettre l'affichage brut volontaire.
     */
    public static function escape(mixed $value): string
    { 
        if ($value instanceof RawHtml) {
            return (string) $value;
        }

    // Protection explicite contre les ressources PHP (ex: fichiers, sockets)
        if (is_resource($value)) {
            return '[Resource: ' . get_resource_type($value) . ']';
        }

    // Protection contre les fonctions anonymes / closures injectées par erreur
        if ($value instanceof \Closure) {
            return '[Closure]';
        }

        if (is_scalar($value)) {
            return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        if (is_object($value)) {
            if (method_exists($value, '__toString')) {
                try {
                    return htmlspecialchars($value->__toString(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                } catch (\Throwable $e) {
                    return '[Error rendering object: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ']';
                }
            }
        // Objet opaque sans méthode magique d'affichage
            return '[Object: ' . get_class($value) . ']';
        }

        if (is_array($value)) {
            return '[Array]';
        }

        return '';
    }
}
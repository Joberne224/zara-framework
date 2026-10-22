<?php
declare(strict_types=1);

namespace Zara\Core\Error\Renderer;

interface RendererInterface
{
    /**
     * Transforme une exception en représentation lisible.
     * Retourne une chaîne prête à envoyer (HTML, texte, JSON...).
     */
    public function render(\Throwable $e): string;
}
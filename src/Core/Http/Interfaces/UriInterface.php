<?php
// file: Zara/Core/Http/UriInterface.php
declare(strict_types=1);

namespace Zara\Core\Http\Interfaces;

interface UriInterface
{
    /**
     * Retourne le schéma de l'URI, sans les caractères de fin "://".
     *
     * Par exemple, "http", "https".
     */
    public function getScheme(): string;

    /**
     * Retourne l'hôte de l'URI.
     *
     * Par exemple, "www.example.com".
     */
    public function getHost(): string;

    /**
     * Retourne le port de l'URI.
     *
     * Par exemple, 80 ou 443.
     */
    public function getPort(): ?int;

    /**
     * Retourne le chemin de l'URI.
     *
     * Par exemple, "/products/view".
     */
    public function getPath(): string;

    /**
     * Retourne la chaîne de requête de l'URI.
     *
     * Par exemple, "id=123".
     */
    public function getQuery(): string;

    /**
     * Retourne le fragment de l'URI.
     *
     * Par exemple, "reviews".
     */
    public function getFragment(): string;

    /**
     * Retourne une nouvelle instance avec le chemin spécifié.
     * Cette méthode doit préserver l'immuabilité de l'objet.
     */
    public function withPath(string $path): self;

    /**
     * Retourne une nouvelle instance avec la chaîne de requête spécifiée.
     * Cette méthode doit préserver l'immuabilité de l'objet.
     */
    public function withQuery(string $query): self;

    /**
     * Retourne une représentation sous forme de chaîne de caractères de l'URI.
     */
    public function __toString(): string;
}
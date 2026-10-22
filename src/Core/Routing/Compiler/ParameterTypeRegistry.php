<?php
declare(strict_types=1);

namespace Zara\Core\Routing\Compiler;

/**
 * Registre des types de paramètres intégrés ({param:type}) et de leur
 * fragment regex associé.
 *
 * Responsabilité unique : traduire un nom de type ('int', 'slug', 'any'...)
 * en fragment PCRE utilisable dans un groupe nommé, sans jamais connaître
 * la notion de path, de placeholder ou de compilation.
 *
 * Extrait de RouteCompiler pour permettre la réutilisation (introspection
 * des types disponibles depuis une commande CLI, YamlRouteLoader, etc.)
 * sans dépendre de tout l'appareil de compilation de routes.
 *
* @internal Composant interne de RouteCompiler, ne pas utiliser hors de ce sous-namespace.
 * @package Zara\Core\Routing\Compiler
 */
final class ParameterTypeRegistry
{
    /**
     * @var array<string, string> Mapping type → fragment regex (sans groupe nommé)
     *
     * 'any' est un wildcard multi-segments : contrairement à tous les autres
     * types (bornés par DEFAULT_PATTERN ou une regex propre qui exclut '/'),
     * '.*' capture aussi les slashes. Voir RoutePathValidator::assertAtMostOneWildcard().
     */
    private const BUILT_IN_TYPES = [
        'int'     => '\d+',
        'float'   => '\b\d+\.\d+\b',
        'bool'    => '(?:1|0|true|false)',
        'string'  => '[a-zA-Z]+',
        'slug'    => '[a-z0-9-]+',
        'uuid'    => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}',
        'email'   => '[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}',
        'version' => 'v?[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?',
        'any'     => '.*',
        '*'       => '.*',
    ];

    /** Fragment utilisé quand aucun type ni contrainte n'est fourni */
    private const DEFAULT_PATTERN = '[^/]+';

    /**
     * Retourne le fragment regex correspondant à un type intégré,
     * ou le pattern par défaut si le type est inconnu / absent.
     */
    public function patternFor(string $type): string
    {
        return self::BUILT_IN_TYPES[$type] ?? self::DEFAULT_PATTERN;
    }

    /**
     * 'any' (et son alias '*') sont les seuls types wildcard multi-segments :
     * ils capturent les slashes, contrairement à tous les autres types qui
     * restent bornés à un seul segment de path.
     */
    public function isWildcard(string $type): bool
    {
        return $type === 'any' || $type === '*';
    }

    public function defaultPattern(): string
    {
        return self::DEFAULT_PATTERN;
    }

    /**
     * @return list<string> Les noms de types intégrés reconnus (hors alias '*')
     */
    public function knownTypes(): array
    {
        return array_values(array_diff(array_keys(self::BUILT_IN_TYPES), ['*']));
    }
}
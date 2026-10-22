<?php
declare(strict_types=1);

namespace Zara\Core\Routing\Compiler;

/**
 * Assembles an anchored PCRE pattern from a path annotated with placeholders
 * ({param}, {param:type}, {param?}, {param:type?}).
 *
 * Single responsibility: transform a path string into a preg_match-able regex,
 * and provide the regex fragments reused elsewhere (RouteCompiler::constrain(),
 * RoutePathValidator) to recognize a given placeholder.
 *
 * Knows nothing about RouteCompiler state (compiledPath, cache...):
 * it receives a path as input and returns a pattern, with no side effects.
 *
 * @internal Internal component of RouteCompiler, do not use outside this sub-namespace.
 * @package Zara\Core\Routing\Compiler
 */   
final class RoutePatternAssembler
{
    /** Regex that recognizes the {param} or {param:type} tags in a path.
     *  The parameter name must start with [a-zA-Z_] (never a digit)
     *  because PCRE disallows named groups whose name starts with a digit. */
    public const PLACEHOLDER_REGEX = '#\{([a-zA-Z_]\w*)(?::(\w+))?\}#';

    /**
     * Optional placeholder: {name?} or {name:type?}, preceded by its '/' which must
     * disappear along with it if the parameter is absent
     * (e.g. 'posts/{page?}' -> 'posts').
     */   
    public const OPTIONAL_PLACEHOLDER_REGEX = '#/\{([a-zA-Z_]\w*)(?::(\w+))?\?\}#';

    /** Recognizes indifféremment un placeholder obligatoire ou optionnel, sans
     *  capturer le '/' précédent — utilisé pour la simple extraction de noms. */
    private const ANY_PLACEHOLDER_REGEX = '#\{([a-zA-Z_]\w*)(?::(?:\w+))?\??\}#';

    public function __construct(
        private readonly ParameterTypeRegistry $types = new ParameterTypeRegistry()
    ) {
    }

    /**
     * Construit le pattern preg complet (avec ancres ^ … $) à partir d'un
     * path de travail (potentiellement déjà partiellement contraint via
     * des groupes nommés custom insérés par RouteCompiler::constrain()).
     */
    public function buildMatchPattern(string $compiledPath): string
    {
        // 1) Placeholders optionnels : le '/' précédent est inclus dans le
        //    groupe non-capturant rendu optionnel par le '?' final, sinon
        //    'posts/{page?}' matcherait 'posts/' mais jamais 'posts' seul.
        $pattern = preg_replace_callback(
            self::OPTIONAL_PLACEHOLDER_REGEX,
            fn(array $m) => '(?:/' . $this->namedGroup($m[1], $this->types->patternFor($m[2] ?? '')) . ')?',
            $compiledPath
        );
        $pattern ??= $compiledPath;

        // 2) Placeholders obligatoires restants.
        $pattern = preg_replace_callback(
            self::PLACEHOLDER_REGEX,
            fn(array $m) => $this->namedGroup($m[1], $this->types->patternFor($m[2] ?? '')),
            $pattern
        );

        // preg_replace_callback ne peut retourner null ici que si sa propre
        // regex interne (PLACEHOLDER_REGEX, statique et valide) échoue, ce qui
        // n'arrive pas en pratique ; on protège quand même avec un fallback.
        $pattern ??= $compiledPath;

        return '#^' . $pattern . '$#';
    }

    /**
     * Construit la regex qui reconnaît la balise d'un paramètre donné dans un path.
     * Utilisé par RouteCompiler::constrain() pour localiser le placeholder à remplacer.
     */
    public function placeholderRegexFor(string $param): string
    {
        return '#\{' . preg_quote($param, '#') . '(?::\w+)?\}#';
    }

    /**
     * Construit un groupe nommé PCRE : (?P<n>pattern).
     */
    public function namedGroup(string $name, string $pattern): string
    {
        return '(?P<' . $name . '>' . $pattern . ')';
    }

    /**
     * Noms de tous les paramètres déclarés dans un path (obligatoires et
     * optionnels confondus), dans l'ordre d'apparition.
     *
     * @return list<string>
     */
    public function extractParameterNames(string $originalPath): array
    {
        preg_match_all(self::ANY_PLACEHOLDER_REGEX, $originalPath, $matches);

        return $matches[1];
    }
}
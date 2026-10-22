<?php
declare(strict_types=1);

namespace Zara\Core\Routing\Compiler;

use InvalidArgumentException;

/**
 * Valide les invariants structurels du langage de routes de Zara :
 * noms de paramètres, unicité du wildcard ':any', position des paramètres
 * optionnels, validité syntaxique des regex de contrainte.
 *
 * Responsabilité unique : détecter et rejeter, via des exceptions explicites,
 * toute déclaration de path ambiguë ou invalide — avant qu'elle ne produise
 * un comportement de matching silencieusement incorrect.
 *
 * Stateless et sans dépendance : chaque assertion reçoit les chaînes dont
 * elle a besoin en paramètre plutôt que de lire un état interne, ce qui la
 * rend testable indépendamment de tout cycle de vie de RouteCompiler.
 *
* @internal Composant interne de RouteCompiler, ne pas utiliser hors de ce sous-namespace.
 * @package Zara\Core\Routing\Compiler
 */
final class RoutePathValidator
{
    /**
     * @throws InvalidArgumentException Si $param n'est pas un nom de groupe PCRE valide
     */
    public function assertParamNameValid(string $param): void
    {
        // PCRE exige que le nom d'un groupe nommé commence par une lettre ou _
        if (!preg_match('/^[a-zA-Z_]\w*$/', $param)) {
            throw new InvalidArgumentException(
                sprintf(
                    "Le nom de paramètre '{%s}' est invalide : un nom de groupe PCRE doit commencer " .
                    "par une lettre ou un underscore (pas un chiffre).",
                    $param
                )
            );
        }
    }

    /**
     * @throws InvalidArgumentException Si $param n'est pas trouvé dans $compiledPath
     */
    public function assertParamExists(
        string $param,
        string $placeholderRegex,
        string $compiledPath,
        string $originalPath
    ): void {
        if (!preg_match($placeholderRegex, $compiledPath)) {
            throw new InvalidArgumentException(
                sprintf(
                    "Le paramètre '{%s}' n'existe pas dans le path '%s'.",
                    $param,
                    $originalPath
                )
            );
        }
    }

    /**
     * {param:any} capture '.*', y compris les slashes, et PCRE backtracke très
     * bien pour le faire cohabiter avec ce qui suit dans le path (ex.
     * 'public/{path:any}/contact' fonctionne sans souci de position). La
     * seule chose à interdire est d'en avoir DEUX dans le même path : deux
     * '.*' séparés rendraient le découpage ambigu (où finit le premier, où
     * commence le second ?) et exposent à un backtracking PCRE dégradé en
     * performance pour un cas d'usage qui n'a de toute façon aucun sens
     * pratique.
     *
     * @throws InvalidArgumentException Si le path contient plus d'un wildcard ':any'
     */
    public function assertAtMostOneWildcard(string $compiledPath, string $originalPath): void
    {
        if (!preg_match_all(RoutePatternAssembler::PLACEHOLDER_REGEX, $compiledPath, $matches)) {
            return;
        }

        $wildcardCount = 0;

        foreach ($matches[2] as $type) {
            if ($type === 'any') {
                $wildcardCount++;
            }
        }

        if ($wildcardCount > 1) {
            throw new InvalidArgumentException(
                sprintf(
                    "Le path '%s' contient plusieurs paramètres wildcard ':any' : un seul est autorisé par path.",
                    $originalPath
                )
            );
        }
    }

    /**
     * Comme pour les wildcards, un paramètre optionnel doit être le dernier
     * élément du path : 'posts/{page?}/{sort?}' est ambigu (que devient
     * '/{sort?}' si {page} est absent ?), et 'posts/{page?}/comments' rendrait
     * 'comments' indiscernable d'une valeur de {page}. On refuse donc dès la
     * déclaration plutôt que de laisser un matching silencieusement incorrect.
     *
     * @throws InvalidArgumentException Si un optionnel n'est pas le dernier élément du path
     */
    public function assertOptionalIsTrailing(string $compiledPath, string $originalPath): void
    {
        if (!preg_match_all(
            RoutePatternAssembler::OPTIONAL_PLACEHOLDER_REGEX,
            $compiledPath,
            $matches,
            PREG_OFFSET_CAPTURE
        )) {
            return;
        }

        $total = count($matches[0]);

        foreach ($matches[0] as $i => [$fullMatch, $offset]) {
            $isLastPlaceholder = $i === $total - 1;
            $isAtEndOfPath     = ($offset + strlen($fullMatch)) === strlen($compiledPath);

            if (!$isLastPlaceholder || !$isAtEndOfPath) {
                throw new InvalidArgumentException(
                    sprintf(
                        "Le paramètre optionnel '{%s}' doit être le dernier élément du path '%s'.",
                        $matches[1][$i][0],
                        $originalPath
                    )
                );
            }
        }
    }

    /**
     * @throws InvalidArgumentException Si $pattern n'est pas une regex valide
     */
    public function assertPatternValid(string $param, string $pattern): void
    {
        // On teste le groupe nommé complet (?P<n>pattern) et non juste (?:pattern),
        // afin de détecter dès la déclaration toute erreur PCRE qui surgirait dans match().
        if (@preg_match('#(?P<' . $param . '>' . $pattern . ')#', '') === false) {
            throw new InvalidArgumentException(
                sprintf(
                    "Le pattern regex '%s' pour le paramètre '{%s}' est invalide.",
                    $pattern,
                    $param
                )
            );
        }
    }
}
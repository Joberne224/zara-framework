<?php
declare(strict_types=1);

namespace Zara\Core\Routing\Compiler;

use InvalidArgumentException;

/**
 * Reconstructs a concrete path by substituting each {param} or {param:type}
 * in the original path with its value from a given set of parameters.
 *
 * Single responsibility: the inverse of matching. Used for URL generation
 * from a named route (see Route::generate()).
 *
 * Always starts from the original path — never mutated by constrain() — so it
 * always sees the raw placeholders, including for parameters that received a
 * custom constraint. No type/constraint validation is performed here: this is
 * a generation tool, not a matching one.
 *
 * @internal Internal component of RouteCompiler, do not use outside this sub-namespace.
 * @package Zara\Core\Routing\Compiler
 */   
final class RouteUrlGenerator
{
    /**
     * Characters that change the structure of a URL ('?' and '#' end the path,
     * browsers read '\' as '/'). None of the built-in parameter types can match
     * them, so encoding them never breaks a route that worked before.
     */
    private const UNSAFE_PATH_CHARS = ['\\' => '%5C', '?' => '%3F', '#' => '%23'];

    /**
     * @param array<string, scalar> $params
     *
     * @throws InvalidArgumentException Si un paramètre requis par le path est absent de $params
     */
    public function generate(string $originalPath, array $params): string
    {
        // 1) Placeholders optionnels : remplacés (slash compris) par leur
        //    valeur si présente dans $params, sinon simplement supprimés —
        //    c'est tout le sens d'un paramètre facultatif.
        $result = preg_replace_callback(
            RoutePatternAssembler::OPTIONAL_PLACEHOLDER_REGEX,
            fn(array $m) => array_key_exists($m[1], $params) ? '/' . strtr((string) $params[$m[1]], self::UNSAFE_PATH_CHARS) : '',
            $originalPath
        );
        $result ??= $originalPath;

        // 2) Placeholders obligatoires restants.
        $result = preg_replace_callback(
            RoutePatternAssembler::PLACEHOLDER_REGEX,
            function (array $m) use ($params, $originalPath) {
                $name = $m[1];

                if (!array_key_exists($name, $params)) {
                    throw new InvalidArgumentException(
                        sprintf(
                            "Parameter '%s' is missing to generate the URL for '%s'.",
                            $name,
                            $originalPath
                        )
                    );
                }

                return strtr((string) $params[$name], self::UNSAFE_PATH_CHARS);
            },
            $result
        );

        return $result ?? $originalPath;
    }
}
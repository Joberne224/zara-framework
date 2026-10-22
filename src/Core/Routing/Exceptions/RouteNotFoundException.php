<?php
declare(strict_types=1);

namespace Zara\Core\Routing\Exceptions;

/**
 * Levée quand aucune route enregistrée, sous aucune méthode HTTP, ne
 * correspond à l'URL demandée (404 Not Found).
 *
 * Sœur de {@see RequestMethodNotFoundException} (405) : les deux sont levées
 * depuis le même point de décision, {@see \Zara\Core\Routing\Internal\RouteMatcher::matchPath()}.
 * Hérite de RuntimeException — comme le reste des erreurs d'exécution du
 * framework (RouteRegistry, RouteCache) — plutôt que du plus générique
 * \Exception, pour permettre un catch(\RuntimeException) cohérent sur
 * l'ensemble de la couche routing, symétrique à RequestMethodNotFoundException.
 *
 * @package Zara\Core\Routing\Exceptions
 */
class RouteNotFoundException extends \RuntimeException {}
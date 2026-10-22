<?php
declare(strict_types=1);

namespace Zara\Core\Routing\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Levée quand une méthode HTTP donnée n'est pas autorisée pour un path qui,
 * lui, correspond bien à une route enregistrée sous une autre méthode.
 *
 * Sémantique HTTP 405 Method Not Allowed : contrairement à un 404, le client
 * a visé une ressource qui existe, mais avec le mauvais verbe. La RFC 9110
 * impose dans ce cas un header `Allow` listant les méthodes acceptées — c'est
 * exactement ce que transporte $allowedMethods ici, pour que la couche
 * ErrorHandler / middleware puisse le renvoyer sans avoir à re-matcher.
 *
 * NOTE POUR JOBERNE : ce fichier n'a pas été fourni dans l'upload d'origine ;
 * je l'ai reconstruit avec une signature volontairement rétrocompatible
 * (seul $message est requis, $allowedMethods est optionnel et vide par
 * défaut) pour ne rien casser si une version différente existe déjà dans le
 * framework. Si ta version réelle diffère (ex. hérite d'une HttpException
 * avec code de statut), reporte juste la propriété $allowedMethods /
 * getAllowedMethods() dedans plutôt que de remplacer le fichier tel quel.
 *
 * @package Zara\Core\Routing\Exceptions
 */
class RequestMethodNotFoundException extends RuntimeException
{
    /**
     * @param list<string> $allowedMethods Méthodes HTTP valides pour ce path (ex. ['GET', 'POST'])
     */
    public function __construct(
        string $message = '',
        private readonly array $allowedMethods = [],
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /** @return list<string> */
    public function getAllowedMethods(): array
    {
        return $this->allowedMethods;
    }
}
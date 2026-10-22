<?php

/**
 * Router script pour `php -S <host>:<ssePort> sse-entrypoint.php`.
 *
 * Lancé par LiveServerCommand via proc_open() avec les variables
 * d'environnement suivantes :
 *   ZARA_AUTOLOAD    → chemin absolu vers vendor/autoload.php
 *   ZARA_SIGNAL_PATH → chemin absolu vers le fichier signal
 */

declare(strict_types=1);

// Le SAPI cli-server (php -S) ne réinjecte pas les variables d'environnement
// du processus dans $_SERVER pour le script routeur — seuls les champs CGI
// de la requête HTTP y figurent. Il faut donc passer par getenv(), qui lui
// voit bien l'environnement transmis via proc_open(). Attention : getenv()
// renvoie false (pas null) en l'absence de variable, d'où les comparaisons
// explicites plutôt qu'un simple ??.
$autoload = getenv('ZARA_AUTOLOAD');

if ($autoload === false || $autoload === '' || !file_exists($autoload)) {
    http_response_code(500);
    echo "Zara Live Server : autoloader introuvable ({$autoload})";
    exit(1);
}

require_once $autoload;

use Zara\Core\LiveServer\ChangeSignal;
use Zara\Core\LiveServer\SseHandler;

$signalPath = getenv('ZARA_SIGNAL_PATH');

if ($signalPath === false || $signalPath === '') {
    $signalPath = sys_get_temp_dir() . '/zara-reload.sig';
}

(new SseHandler(new ChangeSignal($signalPath)))->handle();
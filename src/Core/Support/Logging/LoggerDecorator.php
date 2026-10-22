<?php

declare(strict_types=1);

namespace Zara\Core\Support\Logging;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;
use Throwable;

final class LoggerDecorator extends AbstractLogger
{
    public function __construct(
        private LoggerInterface $innerLogger
    ) {}

    /**
     * Permet de remplacer le logger sous-jacent (ex: passer de NullLogger à Monolog)
     * sans détruire cette instance de décorateur partagée par le Container et ConfigLoader.
     */
    public function setInnerLogger(LoggerInterface $innerLogger): void
    {
        // Évite la sur-décoration si on repasse un LoggerDecorator
        $this->innerLogger = $innerLogger instanceof self 
            ? $innerLogger->innerLogger 
            : $innerLogger;
    }

    public function getInnerLogger(): LoggerInterface
    {
        return $this->innerLogger;
    }

    public function log(mixed $level, Stringable|string $message, array $context = []): void
    {
        try {
            $this->innerLogger->log($level, $message, $context);
        } catch (Throwable $e) {
            $this->fallbackLog($level, $message, $context, $e);
        }
    }

    private function fallbackLog(
        mixed $level,
        Stringable|string $message,
        array $context,
        Throwable $exception
    ): void {
        try {
            $rawMessage = match (true) {
                is_string($message) => $message,
                $message instanceof Stringable => (string) $message,
                default => get_debug_type($message),
            };

            $contextJson = $this->formatContext($context);

            $fallbackMessage = sprintf(
                '[Zara Logger Fallback] Primary logger failed (%s: "%s"). Original [%s]: %s%s',
                $exception::class,
                $exception->getMessage(),
                strtoupper((string) $level),
                $rawMessage,
                $contextJson
            );

            @error_log($fallbackMessage);
        } catch (Throwable) {
            // Critical Failure: Both primary and fallback logging failed.
        }
    }

    /**
     * Extrait les détails d'un objet Throwable dans le contexte avant sérialisation JSON
     * pour éviter d'obtenir un objet vide "{}" dans les logs de secours.
     */
    private function formatContext(array $context): string
    {
        if (empty($context)) {
            return '';
        }

        if (isset($context['exception']) && $context['exception'] instanceof Throwable) {
            $e = $context['exception'];
            $context['exception'] = [
                'class'   => $e::class,
                'message' => $e->getMessage(),
                'code'    => $e->getCode(),
                'file'    => $e->getFile() . ':' . $e->getLine(),
                'trace'   => explode("\n", $e->getTraceAsString()),
            ];
        }

        $json = @json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return ' Context: ' . ($json !== false ? $json : '[unserializable]');
    }
}
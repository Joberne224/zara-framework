<?php
declare(strict_types=1);

namespace Zara\Core\Error;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Zara\Core\Debug\Debug;
use Zara\Core\Error\HttpException\HttpException;
use Zara\Core\Error\Renderer\CliRenderer;
use Zara\Core\Error\Renderer\HtmlDevRenderer;
use Zara\Core\Error\Renderer\HtmlProdRenderer;
use Zara\Core\Error\Renderer\JsonRenderer;
use Zara\Core\Error\Renderer\RendererInterface;

/**
 * Global error and exception handler for the Zara framework.
 *
 * Registers three PHP handlers:
 * 1. set_error_handler()          — converts reportable PHP errors to ErrorException
 * 2. set_exception_handler()      — captures uncaught exceptions
 * 3. register_shutdown_function() — captures fatal errors (E_ERROR, E_PARSE, etc.)
 *
 * Logging uses the PSR-3 logger when available and falls back to PHP's
 * error_log() if no usable logger is configured or the logger itself fails.
 *
 * Kernel integration:
 * ErrorHandler::create(debug: true, logger: $logger)->register();
 */
final class ErrorHandler
{
    private RendererInterface $renderer;
    private ?self $previousActive = null;
    
    /** Prevents recursive failures if an error renderer itself crashes. */
    private bool $isHandlingException = false;

    /**
     * The currently active instance receives shutdown dispatches. PHP replaces
     * the previous error and exception handlers on each register() call, but
     * shutdown callbacks are only appended and cannot be removed. A static
     * pointer lets the single registered callback always delegate to the latest
     * instance, keeping all three handling paths in sync across repeated calls.
     */
    private static ?self $active = null;

    /** Ensures that repeated register() calls install only one shutdown callback. */
    private static bool $shutdownDispatcherRegistered = false;

    /** PHP error levels treated as fatal during shutdown. */
    private const FATAL_ERRORS = [
        E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR,
        E_PARSE, E_USER_ERROR,
    ];

    /** Maps PHP error levels to PSR-3 logging methods. */
    private const LEVEL_MAP = [
        E_ERROR             => 'critical',
        E_WARNING           => 'warning',
        E_NOTICE            => 'notice',
        E_CORE_ERROR        => 'critical',
        E_CORE_WARNING      => 'warning',
        E_COMPILE_ERROR     => 'critical',
        E_COMPILE_WARNING   => 'warning',
        E_USER_ERROR        => 'error',
        E_USER_WARNING      => 'warning',
        E_USER_NOTICE       => 'notice',
        E_STRICT            => 'debug',
        E_RECOVERABLE_ERROR => 'error',
        E_DEPRECATED        => 'info',
        E_USER_DEPRECATED   => 'info',
    ];

    public function __construct(
        private readonly bool             $debug  = false,
        private readonly ?LoggerInterface $logger = null,
        private readonly string           $editor = 'vscode',
    ) {
        $this->renderer = $this->resolveRenderer();
    }

    public static function create(
        bool             $debug  = false,
        ?LoggerInterface $logger = null,
        string           $editor = 'vscode',
    ): self {
        return new self($debug, $logger, $editor);
    }

    public function register(): void
    {
        if (self::$active === $this) {
            return;
        }

        Debug::enable($this->debug, $this->editor);

        $this->previousActive = self::$active;
        self::$active = $this;

        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);

        // The dispatcher reads self::$active when PHP invokes it rather than
        // capturing this instance at registration time. A later register() call
        // can therefore become active without appending another shutdown callback.
        if (!self::$shutdownDispatcherRegistered) {
            self::$shutdownDispatcherRegistered = true;
            register_shutdown_function([self::class, 'dispatchShutdown']);
        }
    }

    public function unregister(): void
    {
        // An older instance may still be referenced after a newer one was
        // registered. It must not pop the newer instance's PHP handlers.
        if (self::$active !== $this) {
            return;
        }

        restore_error_handler();
        restore_exception_handler();

        // Only deactivate dispatch when this is still the active instance, so
        // unregistering an older instance cannot disable a newer one.
        self::$active = $this->previousActive;
        $this->previousActive = null;
        Debug::enable(
            self::$active?->debug ?? false,
            self::$active?->editor ?? 'vscode',
        );
    }

    /**
     * Single entry point registered with register_shutdown_function(), no
     * matter how often register() was called during the process lifetime.
     * Always delegates to the most recently active instance.
     */
    public static function dispatchShutdown(): void
    {
        self::$active?->handleShutdown();
    }

    public function handleError(int $severity, string $message, string $file, int $line): bool
    {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        throw new \ErrorException($message, 0, $severity, $file, $line);
    }

    public function handleException(\Throwable $e): void
    {
        // Avoid recursive handling if rendering the original exception fails.
        if ($this->isHandlingException) {
            $this->fallbackFatalRender($e);
            exit(1);
        }

        $this->isHandlingException = true;

        // Log the original exception before attempting to render it.
        $this->log($e);
        // Request headers may not have been available when this handler was
        // constructed, so select the renderer against the current request.
        $this->renderer = $this->resolveRenderer();

        // Discard removable output buffers so the error response is not appended
        // to a partially rendered page. Stop if a non-removable buffer is reached.
        while (ob_get_level() > 0) {
            $level = ob_get_level();
            if (!@ob_end_clean() || ob_get_level() >= $level) {
                break;
            }
        }

        if (PHP_SAPI === 'cli') {
            try {
                fwrite(STDERR, $this->renderer->render($e));
            } catch (\Throwable $fatal) {
                $this->fallbackFatalRender($fatal);
            }
            exit(1);
        }

        $statusCode = $e instanceof HttpException ? $e->getStatusCode() : 500;

        if (!headers_sent()) {
            http_response_code($statusCode);
            
            // Select the response format based on the request.
            $contentType = $this->shouldRenderJson() ? 'application/json' : 'text/html';
            header("Content-Type: {$contentType}; charset=UTF-8");
        }

        try {
            echo $this->renderer->render($e);
        } catch (\Throwable $fatal) {
            // Use the emergency renderer if the configured renderer also fails.
            $this->fallbackFatalRender($fatal);
        }

        exit(1);
    }

    public function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error !== null && in_array($error['type'], self::FATAL_ERRORS, true)) {
            $this->handleException(new \ErrorException(
                $error['message'],
                0,
                $error['type'],
                $error['file'],
                $error['line'],
            ));
        }
    }

    /**
     * Log the exception using PSR-3, falling back to PHP's error_log().
     */
    private function log(\Throwable $e): void
    {
        $level   = $this->resolveLogLevel($e);
        $message = sprintf(
            'Uncaught %s: %s in %s:%d',
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        );
        $context = ['exception' => $e];

        try {
            // NullLogger is a valid PSR-3 logger but intentionally persists
            // nothing. Treat it as unavailable so fatal errors still reach the
            // error_log() fallback when no application logger is configured.
            if ($this->logger !== null && !$this->logger instanceof NullLogger) {
                $this->logger->$level($message, $context);
                return;
            }
        } catch (\Throwable) {
            // The logger failed; silently fall back to PHP's native error_log().
        }

        error_log($message . PHP_EOL . $e->getTraceAsString());
    }

    /**
     * Resolve the PSR-3 log level from the exception type or HTTP status.
     */
    private function resolveLogLevel(\Throwable $e): string
    {
        if ($e instanceof HttpException) {
            return $e->getStatusCode() >= 500 ? 'error' : 'warning';
        }

        if ($e instanceof \ErrorException) {
            return self::LEVEL_MAP[$e->getSeverity()] ?? 'error';
        }

        return 'critical';
    }

    /**
     * Select a renderer for the CLI, JSON API, development, or production context.
     */
    private function resolveRenderer(): RendererInterface
    {
        if (PHP_SAPI === 'cli') {
            return new CliRenderer();
        }

        if ($this->shouldRenderJson()) {
            return new JsonRenderer($this->debug);
        }

        return $this->debug ? new HtmlDevRenderer($this->editor) : new HtmlProdRenderer();
    }

    /**
     * Inspect request headers to decide whether the error response should be JSON.
     */
    private function shouldRenderJson(): bool
    {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        foreach (explode(',', $accept) as $acceptedType) {
            [$mediaType, $parameters] = array_pad(explode(';', trim($acceptedType), 2), 2, '');
            if (!self::isJsonMediaType(trim($mediaType))) {
                continue;
            }

            if (preg_match('/(?:^|;)\s*q\s*=\s*([0-9.]+)/i', $parameters, $matches) === 1
                && (float) $matches[1] <= 0.0) {
                continue;
            }

            return true;
        }

        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
        $requestMediaType = trim(explode(';', $contentType, 2)[0]);

        return self::isJsonMediaType($requestMediaType)
            || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest');
    }

    private static function isJsonMediaType(string $mediaType): bool
    {
        return $mediaType === 'application/json'
            || preg_match('/^application\/[a-z0-9!#$&^_.+-]+\+json$/', $mediaType) === 1;
    }

    /**
     * Last-resort plain-text output if the error-handling infrastructure fails.
     */
    private function fallbackFatalRender(\Throwable $e): void
    {
        if (PHP_SAPI === 'cli') {
            $output = $this->debug
                ? "Zara emergency error handler fallback:" . PHP_EOL
                    . '[' . get_class($e) . '] ' . $e->getMessage() . PHP_EOL
                    . 'File: ' . $e->getFile() . ' on line ' . $e->getLine() . PHP_EOL
                    . 'Stack trace:' . PHP_EOL . $e->getTraceAsString() . PHP_EOL
                : "Zara encountered an internal error." . PHP_EOL;
            @fwrite(STDERR, $output);
            return;
        }

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
        }

        echo $this->debug
            ? "Zara emergency error handler fallback:" . PHP_EOL
                . '[' . get_class($e) . '] ' . $e->getMessage() . PHP_EOL
                . 'File: ' . $e->getFile() . ' on line ' . $e->getLine() . PHP_EOL
                . 'Stack trace:' . PHP_EOL . $e->getTraceAsString() . PHP_EOL
            : 'An internal server error occurred.' . PHP_EOL;
    }
}

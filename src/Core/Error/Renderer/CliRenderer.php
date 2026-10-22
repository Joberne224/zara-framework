<?php
declare(strict_types=1);

namespace Zara\Core\Error\Renderer;

/**
 * Renderer for CLI contexts such as commands, scripts, and scheduled tasks.
 *
 * Writes to STDERR and uses ANSI colors when attached to a compatible terminal.
 * The rendered output is returned as a string for callers and tests.
 */
final class CliRenderer implements RendererInterface
{
    private bool $colors;

    public function __construct()
    {
        $this->colors = stream_isatty(STDERR);
    }

    public function render(\Throwable $e): string
    {
        $output  = $this->line('');
        $output .= $this->line($this->red('  [' . $this->safeText(get_class($e)) . ']  '));
        $output .= $this->line('');
        $output .= $this->line('  ' . $this->bold($this->safeText($e->getMessage())));
        $output .= $this->line('');
        $output .= $this->line($this->gray('  in ' . $this->safeText($e->getFile()) . ' on line ' . $e->getLine()));
        $output .= $this->line('');
        $output .= $this->renderTrace($e);

        $previous = $e->getPrevious();
        while ($previous !== null) {
            $output .= $this->line($this->yellow('  Caused by: ' . $this->safeText(get_class($previous))));
            $output .= $this->line('  ' . $this->safeText($previous->getMessage()));
            $output .= $this->line($this->gray('  in ' . $this->safeText($previous->getFile()) . ':' . $previous->getLine()));
            $output .= $this->line('');
            $previous = $previous->getPrevious();
        }

        return $output;
    }

    private function renderTrace(\Throwable $e): string
    {
        $output = $this->line($this->gray('  Stack trace:'));

        foreach ($e->getTrace() as $i => $frame) {
            $class    = $this->safeText($frame['class']    ?? '');
            $type     = $this->safeText($frame['type']     ?? '');
            $function = $this->safeText($frame['function'] ?? '{closure}');
            $file     = $this->safeText($frame['file']     ?? '[internal]');
            $line     = $frame['line']     ?? '';

            $call    = $class ? "{$class}{$type}{$function}()" : "{$function}()";
            $output .= $this->line(sprintf(
                '  %s %s  %s',
                $this->gray("#{$i}"),
                $this->cyan($call),
                $this->gray("{$file}:{$line}")
            ));
        }

        return $output . $this->line('');
    }

    private function line(string $text): string
    {
        return $text . PHP_EOL;
    }

    private function red(string $text): string
    {
        return $this->colors ? "\033[41;97m{$text}\033[0m" : $text;
    }

    private function bold(string $text): string
    {
        return $this->colors ? "\033[1m{$text}\033[0m" : $text;
    }

    private function gray(string $text): string
    {
        return $this->colors ? "\033[90m{$text}\033[0m" : $text;
    }

    private function cyan(string $text): string
    {
        return $this->colors ? "\033[36m{$text}\033[0m" : $text;
    }

    private function yellow(string $text): string
    {
        return $this->colors ? "\033[33m{$text}\033[0m" : $text;
    }

    /** Render terminal control characters visibly instead of executing them. */
    private function safeText(string $text): string
    {
        return preg_replace_callback(
            '/[\x00-\x1F\x7F]/',
            static fn (array $match): string => sprintf('\\x%02X', ord($match[0])),
            $text,
        ) ?? '[unprintable text]';
    }
}

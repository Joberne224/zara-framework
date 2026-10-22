<?php
declare(strict_types=1);

namespace Zara\Core\Error\Renderer;

use Zara\Core\Support\EditorLink;
use Zara\View\Interfaces\TemplateExceptionInterface;

/**
 * Renderer HTML pour l'environnement de développement.
 *
 * Affiche une page complète avec :
 * - Classe et message de l'exception
 * - Fichier et ligne d'origine — cliquable, ouvre l'éditeur configuré
 * - Prise en charge des exceptions de Template via TemplateExceptionInterface (façon Jinja2)
 * - Stack trace complète avec contexte de fichier — chaque frame cliquable
 * - Chaîne des exceptions précédentes — cliquable
 * - Métadonnées PHP / Zara
 */
final class HtmlDevRenderer implements RendererInterface
{
    /**
     * @param string $editor Clé de EditorLink::PRESETS ('vscode', 'phpstorm', ...)
     * ou un template brut '%file%:%line%' personnalisé.
     */
    public function __construct(
        private readonly string $editor = 'vscode',
    ) {}

    public function render(\Throwable $e): string
    {
        $class   = get_class($e);
        $message = htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        
        // --- MÉCANIQUE INTERNE TYPE JINJA2 ---
        // Si l'exception implémente TemplateExceptionInterface, on extrait la source brute (.zara.php ou .twig)
        // au lieu de montrer le fichier compilé et illisible stocké dans le cache.
        $isTemplateError = $e instanceof TemplateExceptionInterface;
        $badgeText = $isTemplateError ? 'Zara Framework — Template Error' : 'Zara Framework — Development';
        
        if ($isTemplateError) {
            /** @var TemplateExceptionInterface $e */
            $displayFile = $e->getTemplateFile();
            $displayLine = $e->getTemplateLine();
        } else {
            $displayFile = $e->getFile();
            $displayLine = $e->getLine();
        }

        $fileHtml = htmlspecialchars($displayFile, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $location = EditorLink::wrap(
            $this->editor,
            $displayFile,
            $displayLine,
            "<span>{$fileHtml}</span> on line <span>{$displayLine}</span>"
        );
        // -------------------------------------

        $trace   = $this->renderTrace($e);
        $chain   = $this->renderChain($e);
        $meta    = $this->renderMeta();

        // Variations esthétiques de l'en-tête selon la source du crash (Rouge sombre profond pour les templates)
        $headerBackground = $isTemplateError ? '#240b0b' : '#0f0f13';
        $borderBottomColor = $isTemplateError ? '#fc8181' : '#e53e3e';

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>{$class}</title>
            <style>
                *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
                body { font-family: 'Segoe UI', system-ui, sans-serif; background: #0f0f13; color: #e2e8f0; line-height: 1.6; }

                .header { background: {$headerBackground}; border-bottom: 3px solid {$borderBottomColor}; padding: 2rem 2.5rem; }
                .header .badge { display: inline-block; background: #e53e3e; color: #fff; font-size: .7rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; padding: .25rem .6rem; border-radius: 4px; margin-bottom: .75rem; }
                .header h1 { font-size: 1.1rem; font-weight: 600; color: #fc8181; margin-bottom: .4rem; font-family: monospace; }
                .header .message { font-size: 1.5rem; font-weight: 700; color: #fff; margin-bottom: .75rem; word-break: break-word; }
                .header .location { font-size: .85rem; color: #718096; font-family: monospace; }
                .header .location span { color: #fc8181; }

                .container { max-width: 1100px; margin: 0 auto; padding: 2rem 2.5rem; }

                .section { margin-bottom: 2rem; }
                .section-title { font-size: .7rem; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; color: #718096; margin-bottom: 1rem; padding-bottom: .5rem; border-bottom: 1px solid #2d3748; }

                .trace { list-style: none; }
                .trace-item { background: #1a202c; border: 1px solid #2d3748; border-radius: 6px; margin-bottom: .5rem; overflow: hidden; }
                .trace-item.internal { opacity: .5; }
                .trace-header { display: flex; align-items: center; gap: 1rem; padding: .75rem 1rem; cursor: pointer; user-select: none; }
                .trace-header:hover { background: #2d3748; }
                .trace-index { font-size: .7rem; font-weight: 700; color: #4a5568; min-width: 1.5rem; }
                .trace-class { color: #63b3ed; font-family: monospace; font-size: .85rem; }
                .trace-arrow { color: #4a5568; }
                .trace-method { color: #68d391; font-family: monospace; font-size: .85rem; }
                .trace-file { margin-left: auto; font-size: .75rem; color: #4a5568; font-family: monospace; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 40%; }
                .trace-file span { color: #fc8181; }

                .trace-body { padding: 1rem; border-top: 1px solid #2d3748; display: none; }
                .trace-body.open { display: block; }
                .trace-args { font-size: .8rem; color: #a0aec0; font-family: monospace; white-space: pre-wrap; word-break: break-all; }

                .chain-item { background: #1a202c; border-left: 3px solid #744210; border-radius: 0 6px 6px 0; padding: 1rem 1.25rem; margin-bottom: .75rem; }
                .chain-class { font-family: monospace; color: #f6ad55; font-size: .85rem; margin-bottom: .25rem; }
                .chain-message { color: #e2e8f0; margin-bottom: .25rem; }
                .chain-location { font-size: .75rem; color: #4a5568; font-family: monospace; }

                .meta-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: .75rem; }
                .meta-item { background: #1a202c; border: 1px solid #2d3748; border-radius: 6px; padding: .75rem 1rem; }
                .meta-label { font-size: .7rem; color: #4a5568; text-transform: uppercase; letter-spacing: .08em; margin-bottom: .25rem; }
                .meta-value { font-family: monospace; font-size: .85rem; color: #63b3ed; }

                .zara-brand { text-align: center; padding: 2rem; color: #2d3748; font-size: .75rem; letter-spacing: .05em; }

                {$this->editorCss()}
            </style>
        </head>
        <body>
            <div class="header">
                <div class="badge">{$badgeText}</div>
                <h1>{$class}</h1>
                <div class="message">{$message}</div>
                <div class="location">in {$location}</div>
            </div>

            <div class="container">
                <div class="section">
                    <div class="section-title">Stack Trace</div>
                    <ul class="trace">{$trace}</ul>
                </div>

                {$chain}
                {$meta}
            </div>

            <div class="zara-brand">ZARA FRAMEWORK · ERROR HANDLER</div>

            <script>
                document.querySelectorAll('.trace-header').forEach(function(header) {
                    header.addEventListener('click', function() {
                        var body = this.nextElementSibling;
                        if (body) body.classList.toggle('open');
                    });
                });
            </script>
        </body>
        </html>
        HTML;
    }

    private function editorCss(): string
    {
        return EditorLink::css();
    }

    private function renderTrace(\Throwable $e): string
    {
        $frames = $e->getTrace();
        $html   = '';

        foreach ($frames as $i => $frame) {
            $rawFile  = $frame['file'] ?? '[internal]';
            $rawLine  = $frame['line'] ?? '';
            $class    = htmlspecialchars($frame['class']    ?? '', ENT_QUOTES, 'UTF-8');
            $type     = htmlspecialchars($frame['type']     ?? '', ENT_QUOTES, 'UTF-8');
            $function = htmlspecialchars($frame['function'] ?? '{closure}', ENT_QUOTES, 'UTF-8');
            $file     = htmlspecialchars($rawFile, ENT_QUOTES, 'UTF-8');
            $line     = $rawLine;
            $args     = $this->formatArgs($frame['args'] ?? []);
            $internal = !isset($frame['file']) ? 'internal' : '';

            $classHtml = $class ? "<span class=\"trace-class\">{$class}</span><span class=\"trace-arrow\">{$type}</span>" : '';
            $fileLink  = EditorLink::wrap($this->editor, $rawFile, $rawLine, "{$file}<span>:{$line}</span>");

            $html .= <<<FRAME
            <li class="trace-item {$internal}">
                <div class="trace-header">
                    <span class="trace-index">#{$i}</span>
                    {$classHtml}<span class="trace-method">{$function}()</span>
                    <span class="trace-file">{$fileLink}</span>
                </div>
                <div class="trace-body">
                    <div class="trace-args">{$args}</div>
                </div>
            </li>
            FRAME;
        }

        return $html;
    }

    private function renderChain(\Throwable $e): string
    {
        $previous = $e->getPrevious();
        if ($previous === null) {
            return '';
        }

        $html = '<div class="section"><div class="section-title">Caused by</div>';

        while ($previous !== null) {
            $class    = htmlspecialchars(get_class($previous), ENT_QUOTES, 'UTF-8');
            $message  = htmlspecialchars($previous->getMessage(), ENT_QUOTES, 'UTF-8');
            $rawFile  = $previous->getFile();
            $file     = htmlspecialchars($rawFile, ENT_QUOTES, 'UTF-8');
            $line     = $previous->getLine();
            $location = EditorLink::wrap($this->editor, $rawFile, $line, "{$file}:{$line}");

            $html .= <<<ITEM
            <div class="chain-item">
                <div class="chain-class">{$class}</div>
                <div class="chain-message">{$message}</div>
                <div class="chain-location">{$location}</div>
            </div>
            ITEM;

            $previous = $previous->getPrevious();
        }

        return $html . '</div>';
    }

    private function renderMeta(): string
    {
        $meta = [
            'PHP Version'  => PHP_VERSION,
            'Zara Version' => '1.0.0',
            'SAPI'         => PHP_SAPI,
            'OS'           => PHP_OS,
            'Memory Peak'  => round(memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MB',
            'Time'         => date('Y-m-d H:i:s'),
            'Request URI'  => $_SERVER['REQUEST_URI']  ?? 'N/A',
            'Method'       => $_SERVER['REQUEST_METHOD'] ?? 'N/A',
        ];

        $items = '';
        foreach ($meta as $label => $value) {
            $value  = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
            $items .= <<<ITEM
            <div class="meta-item">
                <div class="meta-label">{$label}</div>
                <div class="meta-value">{$value}</div>
            </div>
            ITEM;
        }

        return <<<HTML
        <div class="section">
            <div class="section-title">Environment</div>
            <div class="meta-grid">{$items}</div>
        </div>
        HTML;
    }

    private function formatArgs(array $args): string
    {
        if (empty($args)) {
            return 'No arguments';
        }

        return implode("\n", array_map(function ($arg) {
            if (is_string($arg))  return '"' . htmlspecialchars(substr($arg, 0, 100), ENT_QUOTES, 'UTF-8') . '"';
            if (is_int($arg) || is_float($arg)) return (string) $arg;
            if (is_bool($arg))    return $arg ? 'true' : 'false';
            if (is_null($arg))    return 'null';
            if (is_array($arg))   return 'Array(' . count($arg) . ')';
            if (is_object($arg))  return get_class($arg);
            return gettype($arg);
        }, $args));
    }
}
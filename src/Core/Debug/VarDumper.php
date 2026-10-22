<?php
declare(strict_types=1);

namespace Zara\Core\Debug;

use Zara\Core\Support\EditorLink;

/**
 * Enhanced recursive dumper for PHP variables with interactive HTML collapsing,
 * CLI output truncation, object dependency counting, fixed live search,
 * copy-to-clipboard, performance metrics, interactive stack trace, and JSON export.
 */
final class VarDumper
{
    private const MAX_DEPTH            = 8;
    private const MAX_STRING_LENGTH    = 500;
    private const MAX_ARRAY_CLI_ITEMS  = 20;
    private const MAX_ARRAY_HTML_ITEMS = 200;
    private const MAX_COMPACT_ITEMS    = 8;
    private const MAX_COMPACT_STRING   = 120;

    /**
     * Render a value as a short, single-line summary for quick inspection.
     * Nested collections are limited to a small number of entries, long
     * strings are truncated, and the standard depth and redaction rules apply.
     */
    public static function compactToHtml(mixed $var): string
    {
        $normalized = self::normalizeForJson($var, 0, new \SplObjectStorage());
        $summary = self::esc(self::formatCompactValue($normalized));

        return '<div class="vd-compact"><style>.vd-compact{font:13px monospace;background:#18181c;color:#e2e8f0;padding:.65rem 1rem;border:1px solid #2d3748;border-radius:8px;margin:.5rem 0;overflow:auto;white-space:nowrap}.vd-compact-label{color:#a0aec0;margin-right:.6rem}</style><span class="vd-compact-label">Compact dump</span><code>' . $summary . '</code></div>';
    }

    /**
     * Render a value as a compact, single-line terminal summary. ANSI coloring
     * is applied only when standard output is attached to an interactive TTY.
     */
    public static function compactToCli(mixed $var): string
    {
        $normalized = self::normalizeForJson($var, 0, new \SplObjectStorage());
        $summary = self::formatCompactValue($normalized);

        return self::ansi(stream_isatty(STDOUT), 36, 'Compact dump: ' . $summary) . PHP_EOL;
    }

    private static function formatCompactValue(mixed $value, int $depth = 0): string
    {
        if (is_string($value)) {
            if (strlen($value) > self::MAX_COMPACT_STRING) {
                $value = substr($value, 0, self::MAX_COMPACT_STRING) . '…';
            }

            return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        if (!is_array($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            return $encoded === false ? get_debug_type($value) : $encoded;
        }

        if ($depth >= 3) {
            return array_is_list($value) ? '[…]' : '{…}';
        }

        $isList = array_is_list($value);
        $parts = [];
        $shown = 0;
        foreach ($value as $key => $item) {
            if ($shown >= self::MAX_COMPACT_ITEMS) {
                $parts[] = '… +' . (count($value) - $shown) . ' more';
                break;
            }

            $rendered = self::formatCompactValue($item, $depth + 1);
            $parts[] = $isList
                ? $rendered
                : ((string) json_encode((string) $key, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ': ' . $rendered);
            $shown++;
        }

        return ($isList ? '[' : '{') . implode(', ', $parts) . ($isList ? ']' : '}');
    }

    /**
     * Export variable as a clean JSON string with recursion and object handling.
     */
    public static function toJson(mixed $var, int $options = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES): string
    {
        try {
            $normalized = self::normalizeForJson($var, 0, new \SplObjectStorage());
            $json = json_encode($normalized, $options | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        } catch (\Throwable) {
            $json = false;
        }

        // Compared strictly: json_encode(0) is the string "0", which is falsy.
        return $json === false ? '{"error":"Unable to encode variable to JSON"}' : $json;
    }

    /**
     * Compare two values and display changed paths in an HTML table.
     */
    public static function diffToHtml(mixed $before, mixed $after): string
    {
        $changes = self::diffValues(self::normalizeForDiff($before), self::normalizeForDiff($after));

        if ($changes === []) {
            return '<div class="vd-diff"><strong>No differences</strong></div>';
        }

        $rows = '';
        foreach ($changes as $change) {
            $path = self::esc($change['path']);
            $old  = self::esc(self::formatDiffValue($change['before']));
            $new  = self::esc(self::formatDiffValue($change['after']));
            $rows .= "<tr><th>{$path}</th><td class=\"vd-diff-old\">{$old}</td><td class=\"vd-diff-new\">{$new}</td></tr>";
        }

        return '<div class="vd-diff"><style>.vd-diff{font:13px monospace;background:#18181c;color:#e2e8f0;padding:1rem;border:1px solid #2d3748;border-radius:8px;overflow:auto}.vd-diff table{border-collapse:collapse;width:100%}.vd-diff th,.vd-diff td{text-align:left;vertical-align:top;padding:5px 8px;border-bottom:1px solid #2d3748}.vd-diff th{color:#fc8181}.vd-diff-old{color:#fc8181}.vd-diff-new{color:#68d391}</style><table><thead><tr><th>Path</th><th>Before</th><th>After</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }

    /**
     * Compare two values and display changed paths in the terminal.
     */
    public static function diffToCli(mixed $before, mixed $after): string
    {
        $changes = self::diffValues(self::normalizeForDiff($before), self::normalizeForDiff($after));
        $tty = stream_isatty(STDOUT);

        if ($changes === []) {
            return self::ansi($tty, 90, 'No differences') . PHP_EOL;
        }

        $lines = [];
        foreach ($changes as $change) {
            $lines[] = self::ansi($tty, 36, $change['path'])
                . PHP_EOL . '  ' . self::ansi($tty, 31, '− ' . self::formatDiffValue($change['before']))
                . PHP_EOL . '  ' . self::ansi($tty, 32, '+ ' . self::formatDiffValue($change['after']));
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private static function normalizeForDiff(mixed $value): mixed
    {
        $normalized = self::normalizeForJson($value, 0, new \SplObjectStorage());
        return self::removeObjectIds($normalized);
    }

    private static function removeObjectIds(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_key_exists('__class', $value) && array_key_exists('properties', $value)) {
            unset($value['__id']);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::removeObjectIds($item);
        }

        return $value;
    }

    /** @return list<array{path: string, before: mixed, after: mixed}> */
    private static function diffValues(mixed $before, mixed $after, string $path = '$'): array
    {
        if (is_array($before) && is_array($after)) {
            $changes = [];
            foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
                $childPath = is_int($key)
                    ? $path . '[' . $key . ']'
                    : $path . '[' . json_encode((string) $key, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ']';

                if (!array_key_exists($key, $before)) {
                    $changes[] = ['path' => $childPath, 'before' => '*MISSING*', 'after' => $after[$key]];
                } elseif (!array_key_exists($key, $after)) {
                    $changes[] = ['path' => $childPath, 'before' => $before[$key], 'after' => '*MISSING*'];
                } else {
                    array_push($changes, ...self::diffValues($before[$key], $after[$key], $childPath));
                }
            }

            return $changes;
        }

        if ($before !== $after) {
            return [['path' => $path, 'before' => $before, 'after' => $after]];
        }

        return [];
    }

    private static function formatDiffValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        try {
            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (\Throwable) {
            $encoded = false;
        }

        return $encoded === false ? get_debug_type($value) : $encoded;
    }

    private static function normalizeForJson(mixed $var, int $depth, \SplObjectStorage $seen): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            return '… (max depth reached)';
        }

        // NAN and INF have no JSON representation.
        if (is_float($var) && !is_finite($var)) {
            return (string) $var;
        }

        if (is_scalar($var) || $var === null) {
            return $var;
        }

        if (is_array($var)) {
            $result = [];
            foreach ($var as $key => $value) {
                $result[$key] = Redactor::isSensitive($key)
                    ? Redactor::MASK
                    : self::normalizeForJson($value, $depth + 1, $seen);
            }
            return $result;
        }

        if (is_object($var)) {
            if ($var instanceof \Closure) {
                return 'Closure';
            }
            if ($var instanceof \UnitEnum) {
                return $var instanceof \BackedEnum ? $var->value : $var->name;
            }
            if ($var instanceof \DateTimeInterface) {
                return $var->format('Y-m-d H:i:s.u P');
            }

            if ($seen->offsetExists($var)) {
                return '*RECURSION*';
            }

            if (ObjectInspector::isUninitializedLazy($var)) {
                return '*LAZY UNINITIALIZED OBJECT*';
            }

            // Attached only while its properties are walked: an object reached
            // twice by two different paths is shared, not recursive.
            $seen->offsetSet($var);

            $data = [];
            foreach (ObjectInspector::properties($var) as $prop) {
                $propName = $prop->getName();

                if (Redactor::isSensitive($propName)) {
                    $data[$propName] = Redactor::MASK;
                    continue;
                }

                if (!$prop->isInitialized($var)) {
                    $data[$propName] = '*UNINITIALIZED*';
                    continue;
                }

                $data[$propName] = self::normalizeForJson($prop->getValue($var), $depth + 1, $seen);
            }

            $seen->offsetUnset($var);

            return [
                '__class' => get_class($var),
                '__id'    => spl_object_id($var),
                'properties' => $data,
            ];
        }

        return gettype($var);
    }

    // -------------------------------------------------------------------------
    // HTML (Browser)
    // -------------------------------------------------------------------------

    public static function toHtml(mixed $var, ?array $caller, string $editor = 'vscode'): string
    {
        $body   = self::renderHtml($var, 0, new \SplObjectStorage());
        $origin = '';

        // Performance metrics calculation
        $startTime   = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);
        $duration    = round((microtime(true) - $startTime) * 1000, 2);
        $memoryBytes = memory_get_usage(true);
        $memoryStr   = $memoryBytes < 1048576
            ? round($memoryBytes / 1024, 2) . ' KB'
            : round($memoryBytes / 1048576, 2) . ' MB';

        if ($caller !== null && isset($caller['file']) && $caller['file'] !== '') {
            $label  = self::esc($caller['file'] . ':' . $caller['line']);
            $link   = EditorLink::wrap($editor, $caller['file'], $caller['line'], $label);
            $origin = "<div class=\"vd-origin\">called from {$link}</div>";
        }

        // Generate interactive stack trace
        $traceHtml = self::renderStackTraceHtml(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15));

        return <<<HTML
        <div class="vd-wrap">
            <style>
                .vd-wrap { font-family: 'Cascadia Code', 'Fira Code', Consolas, monospace; font-size: 13px; background: #18181c; color: #e2e8f0; border: 1px solid #2d3748; border-radius: 8px; padding: 1rem 1.25rem; margin: .75rem 0; overflow-x: auto; line-height: 1.6; position: relative; }
                .vd-toolbar { display: flex; gap: 8px; margin-bottom: 10px; align-items: center; flex-wrap: wrap; }
                .vd-search { background: #22222b; border: 1px solid #3f4a59; border-radius: 4px; color: #e2e8f0; padding: 4px 8px; font-size: 12px; flex-grow: 1; outline: none; font-family: inherit; min-width: 180px; }
                .vd-search:focus { border-color: #63b3ed; }
                .vd-copy-btn { background: #2b6cb0; border: none; border-radius: 4px; color: #ebf8ff; padding: 4px 10px; font-size: 11px; font-weight: 600; cursor: pointer; transition: background 0.2s; }
                .vd-copy-btn:hover { background: #3182ce; }
                .vd-perf-badge { background: #2d3748; color: #a0aec0; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; }
                .vd-origin { color: #718096; font-size: 11px; margin-bottom: .6rem; border-bottom: 1px solid #2d3748; padding-bottom: .4rem; }
                .vd-trace-node { margin-bottom: .6rem; border-bottom: 1px solid #2d3748; padding-bottom: .4rem; font-size: 11px; color: #a0aec0; }
                .vd-trace-summary { color: #63b3ed; font-weight: 600; }
                .vd-trace-frame { margin: 2px 0; }
                .vd-trace-num { color: #718096; }
                .vd-trace-loc { color: #e2e8f0; }
                .vd-trace-call { color: #b794f4; }
                .vd-null  { color: #a0aec0; font-style: italic; }
                .vd-bool  { color: #f6ad55; font-weight: bold; }
                .vd-num   { color: #63b3ed; font-weight: bold; }
                .vd-str   { color: #68d391; }
                .vd-str-len { color: #718096; font-size: 11px; }
                .vd-type  { color: #b794f4; font-weight: 600; }
                .vd-key   { color: #fc8181; }
                .vd-arrow { color: #4a5568; }
                .vd-indent { padding-left: 1.25rem; border-left: 1px solid #2d3748; margin-left: .25rem; }
                .vd-vis   { color: #a0aec0; font-size: 11px; }
                .vd-badge { display: inline-block; padding: 1px 5px; border-radius: 4px; font-size: 10px; font-weight: 600; margin-right: 4px; text-transform: lowercase; }
                .vd-badge-id { background: #2b6cb0; color: #ebf8ff; }
                .vd-badge-size { background: #2f855a; color: #f0fff4; }
                .vd-badge-deps { background: #805ad5; color: #faf5ff; }
                .vd-badge-flag { background: #742a2a; color: #fff5f5; }
                .vd-meta { color: #a0aec0; font-size: 11px; font-style: italic; margin-bottom: 2px; }
                .vd-node { display: block; margin: 2px 0; }
                .vd-summary { cursor: pointer; user-select: none; outline: none; display: inline-block; }
                .vd-summary:hover { opacity: 0.85; }
                .vd-close { color: #718096; font-weight: 600; }
            </style>
            <script>
                if (!window.vdCopy) {
                    window.vdCopy = function (btn) {
                        const clone = btn.closest('.vd-wrap').cloneNode(true);
                        clone.querySelectorAll('.vd-toolbar, .vd-origin, .vd-trace-node, style, script').forEach(e => e.remove());
                        clone.querySelectorAll('details').forEach(d => d.open = true);

                        // innerText only keeps line breaks for rendered elements: attach the clone off-screen first.
                        clone.style.cssText = 'position:fixed;left:-9999px;white-space:pre-wrap';
                        document.body.appendChild(clone);
                        const text = clone.innerText;
                        clone.remove();

                        const label = btn.textContent;
                        const done = () => {
                            btn.textContent = 'Copied!';
                            setTimeout(() => btn.textContent = label, 1500);
                        };

                        if (navigator.clipboard) {
                            navigator.clipboard.writeText(text).then(done);
                            return;
                        }

                        // navigator.clipboard only exists in secure contexts (https or localhost).
                        const area = document.createElement('textarea');
                        area.value = text;
                        document.body.appendChild(area);
                        area.select();
                        document.execCommand('copy');
                        area.remove();
                        done();
                    };
                }
            </script>
            <div class="vd-toolbar">
                <input type="text" class="vd-search" placeholder="Filter properties, keys or values..." oninput="
                    const term = this.value.toLowerCase().trim();
                    const wrap = this.closest('.vd-wrap');
                    const rows = wrap.querySelectorAll('.vd-node, .vd-indent > div');

                    if (!term) {
                        rows.forEach(r => r.style.display = '');
                        return;
                    }

                    rows.forEach(r => {
                        const match = r.textContent.toLowerCase().includes(term);
                        r.style.display = match ? '' : 'none';
                        if (match) {
                            let parent = r.parentElement.closest('details');
                            while (parent) {
                                parent.open = true;
                                parent.style.display = '';
                                parent = parent.parentElement.closest('details');
                            }
                        }
                    });
                ">
                <span class="vd-perf-badge">⏱ {$duration} ms &nbsp;|&nbsp; 💾 {$memoryStr}</span>
                <button class="vd-copy-btn" onclick="vdCopy(this)">Copy</button>
            </div>
            {$origin}
            {$traceHtml}
            {$body}
        </div>
        HTML;
    }

    private static function renderStackTraceHtml(array $trace): string
    {
        $frames = '';
        $index  = 0;

        foreach ($trace as $frame) {
            // Skip internal VarDumper frames
            if (isset($frame['class']) && $frame['class'] === self::class) {
                continue;
            }

            $file     = $frame['file'] ?? '[internal function]';
            $line     = $frame['line'] ?? '';
            $class    = $frame['class'] ?? '';
            $type     = $frame['type'] ?? '';
            $function = $frame['function'] ?? '';

            $call     = self::esc($class ? "{$class}{$type}{$function}()" : "{$function}()");
            $location = self::esc($line ? "{$file}:{$line}" : $file);

            $frames .= "<div class=\"vd-trace-frame\"><span class=\"vd-trace-num\">#{$index}</span> <span class=\"vd-trace-loc\">"
                    . $location
                    . "</span>: <span class=\"vd-trace-call\">{$call}</span></div>";
            $index++;
        }

        if ($frames === '') {
            return '';
        }

        return <<<HTML
        <details class="vd-trace-node">
            <summary class="vd-summary vd-trace-summary">Stack Trace ({$index} frames)</summary>
            <div class="vd-indent">{$frames}</div>
        </details>
        HTML;
    }

    private static function renderHtml(mixed $var, int $depth, \SplObjectStorage $seen): string
    {
        if ($depth > self::MAX_DEPTH) {
            return '<span class="vd-type">… (max depth reached)</span>';
        }

        return match (true) {
            $var === null      => '<span class="vd-null">null</span>',
            is_bool($var)      => '<span class="vd-bool">' . ($var ? 'true' : 'false') . '</span>',
            is_int($var)       => "<span class=\"vd-num\">{$var}</span> <span class=\"vd-type\">int</span>",
            is_float($var)     => "<span class=\"vd-num\">{$var}</span> <span class=\"vd-type\">float</span>",
            is_string($var)    => self::renderHtmlString($var),
            is_array($var)     => self::renderHtmlArray($var, $depth, $seen),
            is_object($var)    => self::renderHtmlObject($var, $depth, $seen),
            default             => '<span class="vd-type">' . gettype($var) . '</span>',
        };
    }

    private static function renderHtmlString(string $var): string
    {
        $len     = strlen($var);
        $escaped = self::esc(self::truncate($var));

        return "<span class=\"vd-str\">\"{$escaped}\"</span> <span class=\"vd-str-len\">({$len})</span>";
    }

    private static function renderHtmlArray(array $var, int $depth, \SplObjectStorage $seen): string
    {
        if (empty($var)) {
            return '<span class="vd-type">array(0)</span> []';
        }

        $count = count($var);
        $items = '';
        $shown = 0;

        foreach ($var as $key => $value) {
            if ($shown >= self::MAX_ARRAY_HTML_ITEMS) {
                $items .= '<div><span class="vd-str-len">… (' . ($count - $shown) . ' more items)</span></div>';
                break;
            }

            $keyHtml = is_string($key)
                ? '<span class="vd-key">"' . self::esc($key) . '"</span>'
                : "<span class=\"vd-key\">{$key}</span>";

            $valueHtml = Redactor::isSensitive($key)
                ? '<span class="vd-badge vd-badge-flag">redacted</span>'
                : self::renderHtml($value, $depth + 1, $seen);

            $items .= "<div>{$keyHtml} <span class=\"vd-arrow\">=&gt;</span> {$valueHtml}</div>";
            $shown++;
        }

        $open = $depth < 2 ? 'open' : '';

        return <<<HTML
        <details {$open} class="vd-node">
            <summary class="vd-summary"><span class="vd-type">array({$count})</span> [</summary>
            <div class="vd-indent">{$items}</div>
            <div class="vd-close">]</div>
        </details>
        HTML;
    }

    private static function renderHtmlObject(object $var, int $depth, \SplObjectStorage $seen): string
    {
        $class = self::esc(get_class($var));
        $id    = spl_object_id($var);

        if ($var instanceof \Closure) {
            return self::renderHtmlClosure($var);
        }

        if ($var instanceof \UnitEnum) {
            $value = ($var instanceof \BackedEnum) ? ' = ' . self::esc((string) $var->value) : '';
            return "<span class=\"vd-type\">enum({$class}::{$var->name}{$value})</span> <span class=\"vd-badge vd-badge-id\">#{$id}</span>";
        }

        if ($var instanceof \DateTimeInterface) {
            return "<span class=\"vd-type\">{$class}</span> <span class=\"vd-badge vd-badge-id\">#{$id}</span> <span class=\"vd-str\">\"{$var->format('Y-m-d H:i:s.u P')}\"</span>";
        }

        if ($seen->offsetExists($var)) {
            return "<span class=\"vd-type\">{$class}</span> <span class=\"vd-badge vd-badge-id\">#{$id}</span> <span class=\"vd-str-len\">*RECURSION*</span>";
        }

        if (ObjectInspector::isUninitializedLazy($var)) {
            return "<span class=\"vd-type\">{$class}</span> <span class=\"vd-badge vd-badge-id\">#{$id}</span> <span class=\"vd-badge vd-badge-flag\">lazy (uninitialized)</span>";
        }

        $metrics    = ObjectInspector::metrics($var);
        $depsStr    = "{$metrics['dependencies']} " . ($metrics['dependencies'] === 1 ? 'dep' : 'deps');

        $badges     = "<span class=\"vd-badge vd-badge-id\">#{$id}</span>"
                    . "<span class=\"vd-badge vd-badge-size\">{$metrics['size']}</span>"
                    . "<span class=\"vd-badge vd-badge-deps\">{$depsStr}</span>";

        foreach (ObjectInspector::flags($var) as $flag) {
            $badges .= "<span class=\"vd-badge vd-badge-flag\">{$flag}</span>";
        }

        $metaInfo = self::renderObjectMetaHeaderHtml($var);
        $properties = ObjectInspector::properties($var);

        if (empty($properties)) {
            return "<span class=\"vd-type\">{$class}</span> {$badges} {$metaInfo} {}";
        }

        // Attached only while its properties are walked: an object reached
        // twice by two different paths is shared, not recursive.
        $seen->offsetSet($var);

        $items = '';
        foreach ($properties as $prop) {
            $visibility = $prop->isPrivate() ? 'private' : ($prop->isProtected() ? 'protected' : 'public');
            if ($prop->isReadOnly()) {
                $visibility .= ' readonly';
            }

            if (!$prop->isInitialized($var)) {
                $value = '<span class="vd-type">uninitialized</span>';
            } elseif (Redactor::isSensitive($prop->getName())) {
                $value = '<span class="vd-badge vd-badge-flag">redacted</span>';
            } else {
                $value = self::renderHtml($prop->getValue($var), $depth + 1, $seen);
            }

            $name = self::esc($prop->getName());

            $items .= "<div><span class=\"vd-key\">\${$name}</span> "
                . "<span class=\"vd-vis\">[{$visibility}]</span> "
                . "<span class=\"vd-arrow\">=&gt;</span> {$value}</div>";
        }

        $seen->offsetUnset($var);

        $open = $depth < 2 ? 'open' : '';

        return <<<HTML
        <details {$open} class="vd-node">
            <summary class="vd-summary"><span class="vd-type">{$class}</span> {$badges} {$metaInfo} {</summary>
            <div class="vd-indent">{$items}</div>
            <div class="vd-close">}</div>
        </details>
        HTML;
    }

    private static function renderHtmlClosure(\Closure $var): string
    {
        $id  = spl_object_id($var);
        $ref = new \ReflectionFunction($var);
        $file = $ref->getFileName();
        $line = $ref->getStartLine();
        $scope = $ref->getClosureScopeClass()?->getName();

        $info = "defined in " . self::esc("{$file}:{$line}");
        if ($scope !== null) {
            $info .= ' | bound to ' . self::esc($scope);
        }

        return "<span class=\"vd-type\">Closure</span> <span class=\"vd-badge vd-badge-id\">#{$id}</span> <span class=\"vd-str-len\">({$info})</span>";
    }

    private static function renderObjectMetaHeaderHtml(object $object): string
    {
        $ref  = new \ReflectionObject($object);
        $meta = [];
        if ($parent = $ref->getParentClass()) {
            $meta[] = "extends " . $parent->getName();
        }

        $interfaces = $ref->getInterfaceNames();
        if (!empty($interfaces)) {
            $meta[] = "implements " . implode(', ', array_map(fn($i) => (new \ReflectionClass($i))->getShortName(), $interfaces));
        }

        $traits = $ref->getTraitNames();
        if (!empty($traits)) {
            $meta[] = "uses " . implode(', ', array_map(fn($t) => (new \ReflectionClass($t))->getShortName(), $traits));
        }

        if (empty($meta)) {
            return '';
        }

        return '<span class="vd-meta">(' . self::esc(implode(' | ', $meta)) . ')</span>';
    }

    // -------------------------------------------------------------------------
    // CLI (ANSI)
    // -------------------------------------------------------------------------

    public static function toCli(mixed $var, ?array $caller): string
    {
        $isTty = stream_isatty(STDOUT);
        $out   = '';

        if ($caller !== null && isset($caller['file']) && $caller['file'] !== '') {
            $out .= self::ansi($isTty, 90, "called from {$caller['file']}:{$caller['line']}") . PHP_EOL;
        }

        $out .= self::renderCli($var, 0, new \SplObjectStorage(), $isTty) . PHP_EOL;

        return $out;
    }

    private static function renderCli(mixed $var, int $depth, \SplObjectStorage $seen, bool $tty): string
    {
        if ($depth > self::MAX_DEPTH) {
            return self::ansi($tty, 90, '… (max depth reached)');
        }

        return match (true) {
            $var === null   => self::ansi($tty, 90, 'null'),
            is_bool($var)   => self::ansi($tty, 33, $var ? 'true' : 'false'),
            is_int($var), is_float($var) => self::ansi($tty, 36, (string) $var),
            is_string($var) => self::ansi($tty, 32, '"' . self::truncate($var) . '"') . self::ansi($tty, 90, ' (' . strlen($var) . ')'),
            is_array($var)  => self::renderCliArray($var, $depth, $seen, $tty),
            is_object($var) => self::renderCliObject($var, $depth, $seen, $tty),
            default          => gettype($var),
        };
    }

    private static function renderCliArray(array $var, int $depth, \SplObjectStorage $seen, bool $tty): string
    {
        $count = count($var);
        if ($count === 0) {
            return 'array(0) []';
        }

        $indent = str_repeat('  ', $depth + 1);
        $lines  = [];
        $i      = 0;

        foreach ($var as $key => $value) {
            if ($i >= self::MAX_ARRAY_CLI_ITEMS) {
                $remaining = $count - $i;
                $lines[] = $indent . self::ansi($tty, 90, "… ({$remaining} more items)");
                break;
            }

            $k       = is_string($key) ? "\"{$key}\"" : (string) $key;
            $shown   = Redactor::isSensitive($key)
                ? self::ansi($tty, 90, Redactor::MASK)
                : self::renderCli($value, $depth + 1, $seen, $tty);
            $lines[] = $indent . self::ansi($tty, 31, $k) . ' => ' . $shown;
            $i++;
        }

        return 'array(' . $count . ") [\n" . implode("\n", $lines) . "\n" . str_repeat('  ', $depth) . ']';
    }

    private static function renderCliObject(object $var, int $depth, \SplObjectStorage $seen, bool $tty): string
    {
        $class = get_class($var);
        $id    = spl_object_id($var);

        if ($var instanceof \Closure) {
            $ref = new \ReflectionFunction($var);
            return self::ansi($tty, 35, 'Closure') . self::ansi($tty, 90, " #{$id} ({$ref->getFileName()}:{$ref->getStartLine()})");
        }

        if ($var instanceof \UnitEnum) {
            $val = ($var instanceof \BackedEnum) ? " = {$var->value}" : '';
            return self::ansi($tty, 35, "enum({$class}::{$var->name}{$val})") . self::ansi($tty, 90, " #{$id}");
        }

        if ($seen->offsetExists($var)) {
            return self::ansi($tty, 35, $class) . self::ansi($tty, 90, " #{$id}") . ' *RECURSION*';
        }

        if (ObjectInspector::isUninitializedLazy($var)) {
            return self::ansi($tty, 35, $class) . self::ansi($tty, 90, " #{$id}") . ' [lazy, uninitialized]';
        }

        $metrics    = ObjectInspector::metrics($var);
        $depsStr    = "{$metrics['dependencies']} " . ($metrics['dependencies'] === 1 ? 'dep' : 'deps');

        $flags      = implode(',', ObjectInspector::flags($var));
        $flagStr    = $flags !== '' ? " [{$flags}]" : '';

        $header = self::ansi($tty, 35, $class) . self::ansi($tty, 90, " #{$id} ({$metrics['size']}) [{$depsStr}]{$flagStr}");

        $properties = ObjectInspector::properties($var);
        if (empty($properties)) {
            return $header . ' {}';
        }

        // Attached only while its properties are walked: an object reached
        // twice by two different paths is shared, not recursive.
        $seen->offsetSet($var);

        $indent = str_repeat('  ', $depth + 1);
        $lines  = [];

        foreach ($properties as $prop) {
            $vis = $prop->isPrivate() ? 'private' : ($prop->isProtected() ? 'protected' : 'public');

            if (!$prop->isInitialized($var)) {
                $value = self::ansi($tty, 90, 'uninitialized');
            } elseif (Redactor::isSensitive($prop->getName())) {
                $value = self::ansi($tty, 90, Redactor::MASK);
            } else {
                $value = self::renderCli($prop->getValue($var), $depth + 1, $seen, $tty);
            }

            $lines[] = $indent . self::ansi($tty, 31, '$' . $prop->getName())
                . self::ansi($tty, 90, " [{$vis}]") . ' => ' . $value;
        }

        $seen->offsetUnset($var);

        return $header . " {\n" . implode("\n", $lines) . "\n" . str_repeat('  ', $depth) . '}';
    }

    // -------------------------------------------------------------------------
    // Formatting helpers
    // -------------------------------------------------------------------------

    private static function truncate(string $text): string
    {
        return strlen($text) > self::MAX_STRING_LENGTH
            ? substr($text, 0, self::MAX_STRING_LENGTH) . '…'
            : $text;
    }

    /**
     * ENT_SUBSTITUTE: invalid UTF-8 (binary data, a string cut mid-character)
     * becomes "�" instead of making htmlspecialchars() return an empty string.
     */
    private static function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function ansi(bool $enabled, int $code, string $text): string
    {
        $text = self::escapeCliControls($text);

        return $enabled ? "\033[{$code}m{$text}\033[0m" : $text;
    }

    private static function escapeCliControls(string $text): string
    {
        if (preg_match('//u', $text) !== 1) {
            $escaped = preg_replace_callback(
                '/[^\x20-\x7E]/',
                static fn (array $match): string => sprintf('\\x%02X', ord($match[0])),
                $text,
            );
        } else {
            $escaped = preg_replace_callback(
                '/\p{Cc}/u',
                static fn (array $match): string => '\\x' . implode('\\x', str_split(strtoupper(bin2hex($match[0])), 2)),
                $text,
            );
        }

        if ($escaped === null) {
            throw new \RuntimeException('Unable to sanitize terminal output.');
        }

        return $escaped;
    }
}

<?php
declare(strict_types=1);

namespace Zara\View\Registry;

use Zara\View\RawHtml;
use Zara\View\Runtime\JsonEncoder;
use Zara\View\Runtime\UrlSanitizer;

/**
 * Registry of filters available to templates.
 *
 * Default filters are registered in the constructor. Additional filters
 * can be registered with add().
 */
final class FilterRegistry
{
    private array $filters = [];

    public function __construct()
    {
        $this->filters = [
            'upper' => fn($value) => strtoupper((string) $value),
            'capital' => function(?string $value = null) {
                if ($value === null || $value === '') return '';
                return ucfirst($value);
            },
            'lower' => fn($value) => strtolower((string) $value),
            'truncate'  => fn($value, $length) => strlen((string) $value) > $length
                ? substr((string) $value, 0, $length) . '...'
                : (string) $value,
            'escape'    => fn($value) => new RawHtml(htmlspecialchars(
                (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'
            )),
            'safe_url'  => fn($value) => UrlSanitizer::sanitize($value),
            'to_json'   => fn($value) => JsonEncoder::encode($value),
            'length'    => fn($value) => strlen((string) $value),
            'trim'      => fn($value) => trim((string) $value),
            'nl2br'     => fn($value) => new RawHtml(nl2br(htmlspecialchars(
                (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'
            ))),
            'date'      => fn($value, $format = 'Y-m-d') => date($format, strtotime((string) $value)),
        ];
    }

    public function add(string $name, callable $callback): void
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException("Invalid template filter name: {$name}");
        }
        $this->filters[$name] = $callback;
    }

    public function has(string $name): bool
    {
        return isset($this->filters[$name]);
    }

    public function all(): array
    {
        return $this->filters;
    }
}

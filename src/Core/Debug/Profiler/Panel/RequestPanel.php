<?php
declare(strict_types=1);

namespace Zara\Core\Debug\Profiler\Panel;

use Zara\Core\Debug\Redactor;
use Zara\Core\Debug\Profiler\PanelInterface;
use Zara\Core\Http\Interfaces\RequestInterface;
use Zara\Core\Http\Interfaces\SessionInterface;

final class RequestPanel implements PanelInterface
{
    public function __construct(
        private readonly RequestInterface $request, 
        private SessionInterface $session,
    ) {}

    public function getId(): string    { return 'request'; }
    public function getLabel(): string { return 'Request'; }
    public function getIcon(): string  { return '🌐'; }

    public function getSummary(): string
    {
        return $this->request->getMethod()->getName(). ' ' . $this->request->getUri()->getPath();
    }

    public function getBadgeColor(): string
    {
        return 'var(--zdb-blue)';
    }

    public function renderContent(): string
    {
        $method  = htmlspecialchars($this->request->getMethod()->getName(), ENT_QUOTES, 'UTF-8');
        $uri     = htmlspecialchars($this->request->getUri()->getPath(), ENT_QUOTES, 'UTF-8');
        $ip      = htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
        $sapi    = htmlspecialchars(PHP_SAPI, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $php     = htmlspecialchars(PHP_VERSION, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $html = "<table class='zdb-table zdb-request-table'><tbody>
            <tr><td>Method</td><td><span class='zdb-badge zdb-badge-blue'>{$method}</span></td></tr>
            <tr><td>URI</td><td class='zdb-mono'>{$uri}</td></tr>
            <tr><td>Client IP</td><td class='zdb-mono'>{$ip}</td></tr>
            <tr><td>PHP SAPI</td><td>{$sapi}</td></tr>
            <tr><td>PHP Version</td><td>{$php}</td></tr>
        </tbody></table>";

        // GET
        $query = $this->request->getQueryParams()->toArray();
        if ($query !== []) {
            $html .= $this->section('GET', $query);
        }

        // POST
        $post = $this->request->getPostParams()->toArray();
        if ($post !== []) {
            $html .= $this->section('POST', $post);
        }

        // Headers
        $headers = $this->getHeaders();
        if (!empty($headers)) {
            $html .= $this->section('Headers', $headers, flattenValues: true);
        }

        // Session
        $session = $this->session->all();
        if ($session !== []) {
            $html .= $this->section('Session', $session);
        }

        // Cookies
        $cookies = $this->request->getCookie()->toArray();
        if ($cookies !== []) {
            $html .= $this->section('Cookies', array_fill_keys(array_keys($cookies), Redactor::MASK));
        }

        return $html;
    }

    private function section(string $title, array $data, bool $flattenValues = false): string
    {
        $rows = '';
        $shown = 0;
        foreach ($data as $key => $value) {
            if ($shown++ >= 100) {
                $rows .= "<tr><td colspan='2'>Additional values omitted.</td></tr>";
                break;
            }
            $k = htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8');
            $safeValue = Redactor::isSensitive((string) $key)
                ? Redactor::MASK
                : $this->redactNested($value);
            if ($flattenValues && is_array($safeValue)) {
                $encoded = implode(', ', array_map(
                    static fn(mixed $item): string => is_scalar($item) || $item === null
                        ? (string) $item
                        : (json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: get_debug_type($item)),
                    $safeValue
                ));
            } else {
                $encoded = is_array($safeValue)
                    ? json_encode($safeValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
                    : (is_scalar($safeValue) || $safeValue === null ? (string) $safeValue : get_debug_type($safeValue));
            }
            if (is_string($encoded) && strlen($encoded) > 4096) {
                $encoded = substr($encoded, 0, 4096) . '… [truncated]';
            }
            $v = htmlspecialchars(
                $encoded === false ? '[unavailable]' : $encoded,
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            );
            $rows .= "<tr><td class='zdb-key'>{$k}</td><td class='zdb-mono'>{$v}</td></tr>";
        }

        $title = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return "<h4 class='zdb-section-title'>{$title}</h4>
        <table class='zdb-table zdb-request-table'><tbody>{$rows}</tbody></table>";
    }

    private function redactNested(mixed $value, int $depth = 0): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if ($depth >= 8) {
            return '[maximum depth reached]';
        }

        $redacted = [];
        foreach ($value as $key => $item) {
            $redacted[$key] = Redactor::isSensitive((string) $key)
                ? Redactor::MASK
                : $this->redactNested($item, $depth + 1);
        }

        return $redacted;
    }

    private function getHeaders(): array
    {
        return $this->request->getHeaders()->toArray();
    }
}

<?php
declare(strict_types=1);

namespace Zara\Core\Debug\Profiler\Panel;

use Psr\SimpleCache\CacheInterface;
use Zara\Cache\Interfaces\CacheDiagnosticsInterface;
use Zara\Cache\ValidatingCache;
use Zara\Core\Debug\Profiler\PanelInterface;
use Zara\Core\Debug\Profiler\Support\CacheTracker;
use Zara\Core\Debug\Profiler\Support\ProfiledCache;

/** Displays cache operations captured during the current request. */
final class CachePanel implements PanelInterface
{
    public function __construct(private readonly CacheInterface $cache) {}

    public function getId(): string { return 'cache'; }
    public function getLabel(): string { return 'Cache'; }
    public function getIcon(): string { return '🧊'; }

    public function getSummary(): string
    {
        $stats = CacheTracker::current()->getStats();
        return sprintf('%d ops · %d hits · %d misses', $stats['calls'], $stats['hits'], $stats['misses']);
    }

    public function getBadgeColor(): string
    {
        $stats = CacheTracker::current()->getStats();
        if ($stats['errors'] > 0) return 'var(--zdb-err)';
        if ($stats['misses'] > $stats['hits'] && $stats['misses'] > 0) return 'var(--zdb-warn)';
        return 'var(--zdb-ok)';
    }

    public function renderContent(): string
    {
        $tracker = CacheTracker::current();
        $stats = $tracker->getStats();
        $snapshot = null;
        $backend = $this->cache;
        while ($backend instanceof ProfiledCache || $backend instanceof ValidatingCache) {
            $backend = $backend->getBackend();
        }
        if ($backend instanceof CacheDiagnosticsInterface) {
            try {
                $snapshot = $backend->getProfilerSnapshot();
            } catch (\Throwable) {
                // Cache diagnostics must not turn a development response into an error.
            }
        }

        $hitRate = $stats['hits'] + $stats['misses'] > 0
            ? 100 * $stats['hits'] / ($stats['hits'] + $stats['misses'])
            : 0.0;
        $averageTime = $stats['calls'] > 0 ? $stats['total_ms'] / $stats['calls'] : 0.0;

        $html = '<h3 class="zdb-cache-heading">Cache activity</h3>';
        $cards = [
            ['Backend', $stats['driver']],
            ['Operations', (string) $stats['calls']],
            ['Hits', (string) $stats['hits']],
            ['Misses', (string) $stats['misses']],
            ['Hit rate', number_format($hitRate, 1) . '%'],
            ['Time', number_format($stats['total_ms'], 3) . ' ms'],
            ['Average time', number_format($averageTime, 3) . ' ms'],
            ['Errors', (string) $stats['errors']],
        ];
        if ($snapshot !== null) {
            $cards[] = ['Stored entries', (string) $snapshot['entries']];
            $cards[] = ['Expired entries', (string) $snapshot['expired']];
            $expiredRate = $snapshot['entries'] > 0
                ? 100 * $snapshot['expired'] / $snapshot['entries']
                : 0.0;
            $cards[] = ['Expired share', number_format($expiredRate, 1) . '%'];
            $cards[] = ['Stored data', self::formatBytes($snapshot['storage_bytes'])];
        }

        $html .= '<div class="zdb-cache-cards">';
        foreach ($cards as [$label, $value]) {
            $html .= '<div class="zdb-cache-card"><span>' . self::escape($label) . '</span><strong>'
                . self::escape($value) . '</strong></div>';
        }
        $records = $tracker->getRecords();
        if ($records === []) {
            $html .= '</div><h4 class="zdb-cache-heading">Operations</h4>';
            $html .= '<p class="zdb-muted">No cache operations were recorded for this request.</p>';
        } else {
            $operationSummary = [];
            foreach ($records as $record) {
                $operation = $record['operation'];
                if (!isset($operationSummary[$operation])) {
                    $operationSummary[$operation] = [
                        'calls' => 0,
                        'hits' => 0,
                        'misses' => 0,
                        'errors' => 0,
                        'total_ms' => 0.0,
                        'tracks_hits' => in_array($operation, ['get', 'getMultiple', 'has'], true),
                    ];
                }

                ++$operationSummary[$operation]['calls'];
                $operationSummary[$operation]['hits'] += $record['hits'];
                $operationSummary[$operation]['misses'] += $record['misses'];
                $operationSummary[$operation]['errors'] += in_array($record['status'], ['Error', 'Failed'], true) ? 1 : 0;
                $operationSummary[$operation]['total_ms'] += $record['duration_ms'];
            }
            uasort(
                $operationSummary,
                static fn(array $left, array $right): int => $right['total_ms'] <=> $left['total_ms'],
            );

            $html .= '</div><h4 class="zdb-cache-heading">By operation</h4>'
                . '<div class="zdb-cache-table-wrap"><table class="zdb-table zdb-cache-table"><thead><tr>'
                . '<th>Operation</th><th>Calls</th><th>Hits</th><th>Misses</th><th>Errors</th>'
                . '<th class="zdb-cache-time">Total time</th><th class="zdb-cache-time">Average time</th>'
                . '</tr></thead><tbody>';
            foreach ($operationSummary as $operation => $summary) {
                $hits = $summary['tracks_hits'] ? (string) $summary['hits'] : '—';
                $misses = $summary['tracks_hits'] ? (string) $summary['misses'] : '—';
                $html .= '<tr><td>' . self::escape($operation) . '</td><td>' . $summary['calls']
                    . '</td><td>' . $hits . '</td><td>' . $misses . '</td><td>' . $summary['errors']
                    . '</td><td class="zdb-cache-time">' . number_format($summary['total_ms'], 3) . ' ms</td>'
                    . '<td class="zdb-cache-time">'
                    . number_format($summary['total_ms'] / $summary['calls'], 3) . ' ms</td></tr>';
            }
            $html .= '</tbody></table></div><h4 class="zdb-cache-heading">Recent operations</h4>';
            $html .= '<div class="zdb-cache-table-wrap"><table class="zdb-table zdb-cache-table"><thead><tr>'
                . '<th>Operation</th><th>Result</th><th class="zdb-cache-time">Duration</th></tr></thead><tbody>';
            foreach (array_reverse($records) as $record) {
                $statusClass = match ($record['status']) {
                    'Hit', 'OK' => 'zdb-cache-good',
                    'Miss' => 'zdb-cache-miss',
                    default => 'zdb-cache-error',
                };
                $status = $record['status'];
                if ($record['operation'] === 'getMultiple') {
                    $status = sprintf('%d hits · %d misses', $record['hits'], $record['misses']);
                } elseif ($record['items'] > 1 && in_array($record['operation'], ['setMultiple', 'deleteMultiple'], true)) {
                    $status .= ' · ' . $record['items'] . ' items';
                }

                $html .= '<tr><td>' . self::escape($record['operation']) . '</td><td><span class="zdb-cache-status '
                    . $statusClass . '">' . self::escape($status) . '</span></td><td class="zdb-cache-time">'
                    . number_format($record['duration_ms'], 3) . ' ms</td></tr>';
            }
            $html .= '</tbody></table></div>';
        }

        if ($stats['dropped'] > 0) {
            $html .= '<p class="zdb-muted">' . $stats['dropped'] . ' older operations were omitted.</p>';
        }

        if ($snapshot !== null) {
            $html .= '<h4 class="zdb-cache-heading">Stored entries <span class="zdb-cache-count">'
                . $snapshot['entries'] . '</span></h4>';
            if ($snapshot['items'] === []) {
                $html .= '<p class="zdb-muted">The cache backend has no inspectable entries.</p>';
            } else {
                $activeItems = array_values(array_filter(
                    $snapshot['items'],
                    static fn(array $item): bool => $item['expires_in'] !== 0,
                ));
                $expiredItems = array_values(array_filter(
                    $snapshot['items'],
                    static fn(array $item): bool => $item['expires_in'] === 0,
                ));

                if ($activeItems !== []) {
                    $html .= '<h5 class="zdb-cache-subheading">Active entries</h5>'
                        . self::renderEntriesTable($activeItems);
                }
                if ($expiredItems !== []) {
                    $html .= '<details class="zdb-cache-expired"><summary>Expired entries in sample ('
                        . count($expiredItems) . ')</summary>' . self::renderEntriesTable($expiredItems) . '</details>';
                }
                if ($activeItems === [] && $expiredItems !== []) {
                    $html .= '<p class="zdb-muted">No active entries in this sample.</p>';
                }
                if ($snapshot['entries'] > count($snapshot['items'])) {
                    $html .= '<p class="zdb-muted">Showing the first ' . count($snapshot['items']) . ' of '
                        . $snapshot['entries'] . ' entries.</p>';
                }
            }
        }

        return $html . <<<'HTML'
            <style>
                .zdb-cache-heading { margin: 4px 0 12px; color: var(--zdb-text); font-size: 14px; }
                .zdb-cache-cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(125px,1fr)); gap:8px; margin-bottom:18px; }
                .zdb-cache-card { min-width:0; padding:10px 12px; border:1px solid var(--zdb-border); border-radius:6px; background:var(--zdb-bg2); }
                .zdb-cache-card span { display:block; color:var(--zdb-muted); font-size:10px; text-transform:uppercase; letter-spacing:.05em; }
                .zdb-cache-card strong { display:block; margin-top:5px; color:var(--zdb-text); font-size:13px; overflow-wrap:anywhere; }
                .zdb-cache-table-wrap { overflow:auto; }
                .zdb-cache-time { text-align:center !important; white-space:nowrap; }
                .zdb-cache-status { display:inline-block; padding:2px 6px; border-radius:4px; font-size:10px; white-space:nowrap; }
                .zdb-cache-good { color:#86efac; background:rgba(34,197,94,.12); }
                .zdb-cache-miss { color:#fcd34d; background:rgba(245,158,11,.12); }
                .zdb-cache-error { color:#fca5a5; background:rgba(239,68,68,.14); }
                .zdb-cache-subheading { margin:10px 0; color:var(--zdb-muted); font-size:12px; }
                .zdb-cache-expired { margin:8px 0; color:var(--zdb-muted); }
                .zdb-cache-expired summary { cursor:pointer; padding:8px 0; font-size:12px; }
                .zdb-cache-count { display:inline-block; padding:2px 7px; margin-left:5px; border-radius:10px; background:var(--zdb-bg3); color:var(--zdb-muted); font-size:10px; vertical-align:middle; }
            </style>
            HTML;
    }

    /** @param list<array{fingerprint:string,expires_in:int|null,size_bytes:int}> $items */
    private static function renderEntriesTable(array $items): string
    {
        $html = '<div class="zdb-cache-table-wrap"><table class="zdb-table zdb-cache-table"><thead><tr>'
            . '<th>Key fingerprint</th><th>Status</th><th>Expires</th>'
            . '<th class="zdb-cache-time">Value size</th></tr></thead><tbody>';
        foreach ($items as $item) {
            $expiresIn = $item['expires_in'];
            $status = match (true) {
                $expiresIn === null => ['Persistent', 'zdb-cache-good'],
                $expiresIn === 0 => ['Expired', 'zdb-cache-error'],
                $expiresIn <= 300 => ['Expiring soon', 'zdb-cache-miss'],
                default => ['Active', 'zdb-cache-good'],
            };
            $expiration = match (true) {
                $expiresIn === null => 'No expiration',
                $expiresIn === 0 => 'Expired',
                default => date('Y-m-d H:i:s', time() + $expiresIn)
                    . ' · in ' . self::formatDuration($expiresIn),
            };
            $html .= '<tr><td><code>' . self::escape($item['fingerprint']) . '</code></td>'
                . '<td><span class="zdb-cache-status ' . $status[1] . '">' . self::escape($status[0])
                . '</span></td><td>' . self::escape($expiration) . '</td><td class="zdb-cache-time">'
                . self::escape(self::formatBytes($item['size_bytes'])) . '</td></tr>';
        }

        return $html . '</tbody></table></div>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1_048_576) return number_format($bytes / 1024, 1) . ' KB';
        return number_format($bytes / 1_048_576, 2) . ' MB';
    }

    private static function formatDuration(int $seconds): string
    {
        if ($seconds < 60) return $seconds . ' sec';
        if ($seconds < 3600) return (string) intdiv($seconds, 60) . ' min';
        if ($seconds < 86400) return (string) intdiv($seconds, 3600) . ' hr';
        return (string) intdiv($seconds, 86400) . ' days';
    }
}

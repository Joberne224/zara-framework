<?php
declare(strict_types=1);

namespace Zara\Core\Debug\Profiler\Panel;

use Zara\Core\Debug\Profiler\PanelInterface;
use Zara\Database\AbstractSqlDatabase;
use Zara\Database\DatabaseInterface;
use Zara\Core\Debug\Profiler\Support\QueryRecord;
use Zara\Core\Debug\Profiler\Support\QueryTracker;

/** Displays safe database connection and transaction metadata. */
final class DatabasePanel implements PanelInterface
{
    public function __construct(private readonly ?DatabaseInterface $database = null) {}

    public function getId(): string { return 'database'; }
    public function getLabel(): string { return 'Database'; }
    public function getIcon(): string { return '🗄️'; }

    public function getSummary(): string
    {
        if (!$this->database instanceof AbstractSqlDatabase) return 'Unavailable';
        $info = $this->database->getProfilerInfo();
        $count = count(QueryTracker::current()->getRecords());
        return $info['driver'] . ' · ' . $count . ' quer' . ($count === 1 ? 'y' : 'ies');
    }

    public function getBadgeColor(): string
    {
        if (!$this->database instanceof AbstractSqlDatabase) return 'var(--zdb-warn)';
        $info = $this->database->getProfilerInfo();
        $failed = count(array_filter(QueryTracker::current()->getRecords(), static fn(QueryRecord $record): bool => $record->failed));
        return !$info['connected'] && QueryTracker::current()->getRecords() !== [] || $failed > 0
            ? 'var(--zdb-err)'
            : ($info['connected'] ? 'var(--zdb-ok)' : 'var(--zdb-blue)');
    }

    public function renderContent(): string
    {
        if (!$this->database instanceof AbstractSqlDatabase) {
            return '<h3>Database</h3><p class="zdb-muted">No supported SQL database service is configured.</p>';
        }

        $info = $this->database->getProfilerInfo();
        $records = QueryTracker::current()->getRecords();
        $queryCount = count($records);
        $totalMs = array_sum(array_map(static fn(QueryRecord $record): float => $record->durationMs, $records));
        $failedCount = count(array_filter($records, static fn(QueryRecord $record): bool => $record->failed));
        $slowest = null;
        $operations = [];
        foreach ($records as $record) {
            $operation = $this->operationFor($record);
            $operations[$operation] = ($operations[$operation] ?? 0) + 1;
            if ($slowest === null || $record->durationMs > $slowest->durationMs) $slowest = $record;
        }
        ksort($operations);
        $status = $info['connected']
            ? '<span class="zdb-badge zdb-badge-ok">Connected</span>'
            : '<span class="zdb-badge">Not connected</span>';
        $rows = [
            ['Driver', $info['driver']],
            ['Status', $status],
            ['Database', $info['database'] ?? 'Unavailable'],
            ['Host', $info['host'] ?? 'Not applicable'],
            ['Port', $info['port'] === null ? 'Not applicable' : (string) $info['port']],
            ['Charset', $info['charset'] ?? 'Not applicable'],
            ['Transaction depth', (string) $info['transaction_depth']],
        ];

        $html = '<h3>Database connection</h3><table class="zdb-table zdb-database-table"><tbody>';
        foreach ($rows as [$label, $value]) {
            $safeLabel = htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeValue = $label === 'Status' ? $value : htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html .= '<tr><td>' . $safeLabel . '</td><td>' . $safeValue . '</td></tr>';
        }
        $html .= '</tbody></table>';

        $averageMs = $queryCount > 0 ? $totalMs / $queryCount : 0.0;
        $cards = [
            ['Queries', (string) $queryCount],
            ['Total query time', number_format($totalMs, 2) . ' ms'],
            ['Average query time', number_format($averageMs, 2) . ' ms'],
            ['Failed queries', (string) $failedCount],
            ['Slowest query', $slowest === null ? '—' : number_format($slowest->durationMs, 2) . ' ms'],
        ];
        $html .= '<h3 class="zdb-database-section-heading">Request statistics</h3><div class="zdb-database-cards">';
        foreach ($cards as [$label, $value]) {
            $html .= '<div class="zdb-database-card"><span>' . self::escape($label) . '</span><strong>' . self::escape($value) . '</strong></div>';
        }
        $html .= '</div><h4 class="zdb-database-section-heading">Operations</h4>';
        if ($operations === []) {
            $html .= '<p class="zdb-muted">No SQL operations were recorded for this request.</p>';
        } else {
            $html .= '<table class="zdb-table zdb-database-operations"><thead><tr><th>Operation</th><th class="zdb-num">Count</th></tr></thead><tbody>';
            foreach ($operations as $operation => $count) {
                $html .= '<tr><td>' . self::escape($operation) . '</td><td class="zdb-num">' . $count . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }

        if ($failedCount > 0) {
            $html .= '<p class="zdb-database-alert">' . $failedCount . ' failed quer' . ($failedCount === 1 ? 'y' : 'ies') . ' recorded. Open the Queries panel for details.</p>';
        }
        return $html . '<p class="zdb-database-note">Statistics use queries already captured for this request. Connection metadata is read without opening a connection or running a health-check query.</p>';
    }

    private function operationFor(QueryRecord $record): string
    {
        return match ($record->method) {
            'insert', 'update', 'delete' => strtoupper($record->method),
            default => $record->sqlAvailable
                ? (preg_match('/^\s*(SELECT|INSERT|UPDATE|DELETE|WITH|REPLACE|CREATE|ALTER|DROP|PRAGMA)\b/i', $record->sql, $match) === 1
                    ? strtoupper($match[1])
                    : 'OTHER')
                : match ($record->method) {
                    'fetchOne', 'fetchColumn', 'query' => 'READ',
                    default => strtoupper($record->method),
                },
        };
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

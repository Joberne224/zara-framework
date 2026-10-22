<?php
declare(strict_types=1);

namespace Zara\Core\Debug\Profiler\Panel;

use Zara\Core\Debug\Redactor;
use Zara\Core\Debug\Profiler\PanelInterface;
use Zara\Core\Debug\Profiler\Profiler;

final class LogPanel implements PanelInterface
{
    public function __construct(private readonly Profiler $profiler) {}

    public function getId(): string    { return 'logs'; }
    public function getLabel(): string { return 'Logs'; }
    public function getIcon(): string  { return '📋'; }

    public function getSummary(): string
    {
        $count = count($this->profiler->getLogs());
        return $count . ' ' . ($count === 1 ? 'entry' : 'entries');
    }

    public function getBadgeColor(): string
    {
        foreach ($this->profiler->getLogs() as $log) {
            if (in_array($log['level'], ['emergency', 'alert', 'critical', 'error'], true)) {
                return 'var(--zdb-err)';
            }
        }
        foreach ($this->profiler->getLogs() as $log) {
            if ($log['level'] === 'warning') {
                return 'var(--zdb-warn)';
            }
        }
        return 'var(--zdb-ok)';
    }

    public function renderContent(): string
    {
        $logs = $this->profiler->getLogs();

        if (empty($logs)) {
            return "<p class='zdb-muted'>No log recorded during this request.</p>";
        }

        $start = $this->profiler->getStartTime();
        $rows  = '';

        foreach ($logs as $log) {
            $rawLevel = (string) ($log['level'] ?? 'info');
            $level = htmlspecialchars($rawLevel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $timestamp = is_numeric($log['time'] ?? null) ? (float) $log['time'] : $start;
            $elapsed = round(($timestamp - $start) * 1000, 2);
            $wallClock = $this->formatWallClock($timestamp);
            $message = htmlspecialchars((string) ($log['message'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeContext = $this->redactContext($log['context'] ?? []);
            $encodedContext = json_encode(
                $safeContext,
                JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE
            );
            $context = !empty($log['context'])
                ? '<div class="zdb-ctx zdb-mono">' . htmlspecialchars(
                    $encodedContext === false ? '[context unavailable]' : $encodedContext,
                    ENT_QUOTES | ENT_SUBSTITUTE,
                    'UTF-8'
                ) . '</div>'
                : '';

            $badgeClass = $this->badgeClass($rawLevel);

            $rows .= "<tr>
                <td><span class='zdb-badge {$badgeClass}'>{$level}</span></td>
                <td class='zdb-num'>{$wallClock}</td>
                <td class='zdb-num'>{$elapsed} ms</td>
                <td>{$message}{$context}</td>
            </tr>";
        }

        $dropped = $this->profiler->getDroppedLogCount();
        $notice = $dropped > 0
            ? sprintf(
                '<p class="zdb-muted">Showing the latest %d entries; %d older entries were omitted to cap profiler memory use.</p>',
                count($logs),
                $dropped
            )
            : '';

        return $notice . "<table class='zdb-table zdb-log-table'>
            <thead><tr><th>Level</th><th>Time</th><th>+ms</th><th>Message</th></tr></thead>
            <tbody>{$rows}</tbody>
        </table>";
    }

    /**
     * Formats a microtime(true)-style float timestamp as a wall-clock
     * HH:MM:SS.mmm string — the actual moment the log was recorded, as
     * opposed to the +ms column which is relative to request start.
     *
     * Uses an explicit timezone rather than PHP's server-default
     * (date_default_timezone_get(), often UTC) — same .env-driven pattern
     * already used for DB_HOST/DB_USER/... in bootstrap.php. Falls back to
     * the server default when APP_TIMEZONE isn't set, so this stays
     * backward compatible until that .env entry is added.
     */
    private function formatWallClock(float $timestamp): string
    {
        $timezoneName = $_ENV['APP_TIMEZONE'] ?? $_SERVER['APP_TIMEZONE'] ?? date_default_timezone_get();
        try {
            $timezone = new \DateTimeZone((string) $timezoneName);
        } catch (\Throwable) {
            $timezone = new \DateTimeZone(date_default_timezone_get());
        }
        $dateTime = (new \DateTimeImmutable('@' . (int) $timestamp))->setTimezone($timezone);
        $milliseconds = sprintf('%03d', (int) round(($timestamp - floor($timestamp)) * 1000));

        return $dateTime->format('H:i:s') . '.' . $milliseconds;
    }

    private function badgeClass(string $level): string
    {
        return match ($level) {
            'emergency', 'alert', 'critical', 'error' => 'zdb-badge-err',
            'warning'                                  => 'zdb-badge-warn',
            'notice', 'info'                           => 'zdb-badge-blue',
            default                                    => 'zdb-badge-ok',
        };
    }

    private function redactContext(mixed $context, int $depth = 0): mixed
    {
        if (!is_array($context)) {
            return $context;
        }
        if ($depth >= 8) {
            return '[maximum depth reached]';
        }

        $redacted = [];
        foreach ($context as $key => $value) {
            $redacted[$key] = Redactor::isSensitive((string) $key)
                ? Redactor::MASK
                : $this->redactContext($value, $depth + 1);
        }

        return $redacted;
    }
}

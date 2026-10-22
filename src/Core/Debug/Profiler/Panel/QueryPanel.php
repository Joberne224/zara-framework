<?php

declare(strict_types=1);

namespace Zara\Core\Debug\Profiler\Panel;

use Zara\Core\Debug\Profiler\PanelInterface;
use Zara\Core\Debug\Profiler\Support\QueryRecord;
use Zara\Core\Debug\Profiler\Support\QueryTracker;

final class QueryPanel implements PanelInterface
{
    private const SLOW_QUERY_MS = 20.0;
    private const WARN_COUNT = 30;
    private const ERROR_COUNT = 60;

    public function getId(): string
    {
        return 'queries';
    }

    public function getLabel(): string
    {
        return 'Queries';
    }

    public function getIcon(): string
    {
        return '🗄️';
    }

    public function getSummary(): string
    {
        $records = QueryTracker::current()->getRecords();
        $count = count($records);
        $totalMs = QueryTracker::current()->getTotalDurationMs();

        return sprintf('%d quer%s (%.1f ms)', $count, $count > 1 ? 'ies' : 'y', $totalMs);
    }

    public function getBadgeColor(): string
    {
        $count = count(QueryTracker::current()->getRecords());

        return match (true) {
            $count > self::ERROR_COUNT => 'var(--zdb-err)',
            $count > self::WARN_COUNT => 'var(--zdb-warn)',
            default => 'var(--zdb-ok)',
        };
    }

    public function renderContent(): string
    {
        $tracker = QueryTracker::current();
        $records = $tracker->getRecords();

        if ($records === []) {
            return '<h3 style="margin-top:0;color:#6d4aff;">Queries</h3>
                    <p style="color:#718096; font-style:italic;">No query executed on this request.</p>';
        }

        $count = count($records);
        $totalMs = $tracker->getTotalDurationMs();

        $html = sprintf('<h3 style="margin-top:0;color:#6d4aff;">Queries (%d — %.1f ms total)</h3>', $count, $totalMs);

        // --- Repeated queries alert (N+1 signal) ---
        $repeated = $tracker->getRepeatedQueries();
        if ($repeated !== []) {
            arsort($repeated);
            $html .= '<div style="margin-bottom:10px; padding:8px; background:#2a1a1a; border:1px solid #ff9d4a; border-radius:4px;">
                <div style="color:#ff9d4a; font-weight:bold; margin-bottom:4px;">⚠ Repeated queries detected (possible N+1)</div>
                <div style="color:#a08060; font-size:10px; font-style:italic; margin-bottom:6px;">The exact same query ran more than once on this request — often a sign of fetching related rows one-by-one in a loop instead of a single batched query.</div>
                <div style="font-family:monospace; font-size:11px; color:#d0a080; max-height:100px; overflow-y:auto;">';
            foreach ($repeated as $sql => $n) {
                $html .= sprintf('<div style="padding:2px 0;">%d× — %s</div>', $n, htmlspecialchars($sql));
            }
            $html .= '</div></div>';
        }

        if ($tracker->hasRedactedQueries()) {
            $html .= '<p style="margin:0 0 10px; color:#718096; font-size:11px; font-style:italic;">Some queries have their SQL text redacted (logQueries disabled on that database instance) — repeat-query detection skips them.</p>';
        }

        // --- Toolbar: sort / group / export ---
        $html .= $this->renderToolbar();

        // --- Scrollable query list ---
        $html .= '<div id="zdb-query-list" class="zdb-fill" style="font-family:monospace; font-size:11px; background:#111116; padding:10px; border-radius:4px; border:1px solid #2d2d3d;">';

        foreach ($records as $record) {
            $html .= $this->renderRow($record, $record->durationMs > self::SLOW_QUERY_MS);
        }

        $html .= '</div>';

        $html .= $this->renderScript();

        return $html;
    }

    /**
     * Renders one query row, carrying its data twice on purpose: once as
     * visible, formatted HTML (unchanged from before) and once as raw
     * data-* attributes the toolbar script reads to sort/group/export
     * without needing a second request or a server round-trip.
     */
    private function renderRow(QueryRecord $record, bool $isSlow): string
    {
        $methodColor = $record->failed ? '#ff5a5a' : '#7C6DFF';
        $durationColor = $isSlow ? '#ff9d4a' : '#718096';
        $failedSuffix = $record->failed ? ' (failed)' : '';
        $paramSuffix = $record->paramCount > 1 ? 's' : '';
        $displayDuration = sprintf('%.1f ms', $record->durationMs);

        $method = htmlspecialchars($record->method, ENT_QUOTES);
        $sql = htmlspecialchars($record->sql, ENT_QUOTES);
        $table = htmlspecialchars($record->table ?? '', ENT_QUOTES);
        $duration = sprintf('%.4f', $record->durationMs);
        $failedFlag = $record->failed ? 1 : 0;

        $tableBadge = $record->table !== null
            ? sprintf(' · <span style="color:#4a5578;">%s</span>', $table)
            : '';

        return <<<HTML
            <div class="zdb-query-row" data-order="{$record->order}" data-table="{$table}" data-duration="{$duration}" data-method="{$method}" data-failed="{$failedFlag}" data-params="{$record->paramCount}" data-sql="{$sql}" style="padding:5px 0; border-bottom:1px solid #1a1a24; color:#a0aec0;">
                <div style="display:flex; justify-content:space-between; gap:12px; margin-bottom:2px;">
                    <span style="color:{$methodColor}; font-weight:bold; text-transform:uppercase; font-size:10px;">{$method}{$failedSuffix}</span>
                    <span style="color:{$durationColor}; white-space:nowrap;">{$displayDuration}</span>
                </div>
                <div style="overflow-wrap:anywhere; color:#c8cee8;">{$sql}</div>
                <div style="color:#5a6080; font-size:10px; margin-top:1px;">{$record->paramCount} param{$paramSuffix}{$tableBadge}</div>
             </div>
            HTML;
    }

    private function renderToolbar(): string
    {
        return <<<'HTML'
            <style>
                .zdb-toolbar-btn {
                    background:#1a1a24; color:#a0aec0; border:1px solid #2d2d3d;
                    border-radius:4px; padding:4px 10px; font-size:11px; font-family:monospace;
                    cursor:pointer;
                }
                .zdb-toolbar-btn:hover { border-color:#7C6DFF; color:#c8cee8; }
                .zdb-toolbar-btn.is-active { background:#2a2440; border-color:#7C6DFF; color:#c8cee8; }
                .zdb-group-header {
                    margin:8px 0 4px; padding:3px 6px; background:#1a1a24; color:#7C6DFF;
                    font-weight:bold; font-size:10px; border-radius:3px;
                }
            </style>
            <div style="display:flex; gap:6px; margin-bottom:8px;">
                <button type="button" class="zdb-toolbar-btn" id="zdb-sort-btn" onclick="window.__zdbQuerySort()">⇅ Order</button>
                <button type="button" class="zdb-toolbar-btn" id="zdb-group-btn" onclick="window.__zdbQueryGroup()">▤ Group by table</button>
                <button type="button" class="zdb-toolbar-btn" onclick="window.__zdbQueryExport()">⭳ Export JSON</button>
            </div>
            HTML;
    }

    /**
     * Safe to inline directly: renderContent() is emitted straight into the
     * page response (no AJAX/innerHTML injection for this toolbar), so this
     * script tag executes normally as part of the initial parse. Scoped in
     * an IIFE and exposing only 3 window functions to avoid clobbering
     * anything another panel's own script might define.
     */
    private function renderScript(): string
    {
        return <<<'HTML'
            <script>
            (function () {
                var container = document.getElementById('zdb-query-list');
                var sortBtn = document.getElementById('zdb-sort-btn');
                var groupBtn = document.getElementById('zdb-group-btn');
                if (!container || !sortBtn || !groupBtn) { return; }

                function rows() {
                    return Array.prototype.slice.call(container.querySelectorAll('.zdb-query-row'));
                }

                function clearGroupHeaders() {
                    Array.prototype.slice.call(container.querySelectorAll('.zdb-group-header')).forEach(function (h) {
                        h.remove();
                    });
                }

                function byOriginalOrder(a, b) {
                    return parseInt(a.dataset.order, 10) - parseInt(b.dataset.order, 10);
                }

                function byDurationDesc(a, b) {
                    return parseFloat(b.dataset.duration) - parseFloat(a.dataset.duration);
                }

                function applyLayout() {
                    var list = rows();
                    clearGroupHeaders();

                    var sortByDuration = sortBtn.classList.contains('is-active');
                    var grouped = groupBtn.classList.contains('is-active');
                    var comparator = sortByDuration ? byDurationDesc : byOriginalOrder;

                    if (!grouped) {
                        list.sort(comparator);
                        list.forEach(function (r) { container.appendChild(r); });
                        return;
                    }

                    var byTable = {};
                    list.forEach(function (r) {
                        var t = r.dataset.table || 'Other';
                        (byTable[t] = byTable[t] || []).push(r);
                    });

                    var tables = Object.keys(byTable).sort(function (a, b) {
                        var totalA = byTable[a].reduce(function (s, r) { return s + parseFloat(r.dataset.duration); }, 0);
                        var totalB = byTable[b].reduce(function (s, r) { return s + parseFloat(r.dataset.duration); }, 0);
                        return totalB - totalA;
                    });

                    tables.forEach(function (table) {
                        var group = byTable[table].slice().sort(comparator);
                        var total = group.reduce(function (s, r) { return s + parseFloat(r.dataset.duration); }, 0);

                        var header = document.createElement('div');
                        header.className = 'zdb-group-header';
                        header.textContent = table + ' — ' + group.length + ' quer' + (group.length > 1 ? 'ies' : 'y') + ' (' + total.toFixed(1) + ' ms)';
                        container.appendChild(header);
                        group.forEach(function (r) { container.appendChild(r); });
                    });
                }

                window.__zdbQuerySort = function () {
                    sortBtn.classList.toggle('is-active');
                    sortBtn.textContent = sortBtn.classList.contains('is-active') ? '⇅ Duration ↓' : '⇅ Order';
                    applyLayout();
                };

                window.__zdbQueryGroup = function () {
                    groupBtn.classList.toggle('is-active');
                    applyLayout();
                };

                window.__zdbQueryExport = function () {
                    var data = rows().sort(byOriginalOrder).map(function (r) {
                        return {
                            order: parseInt(r.dataset.order, 10),
                            table: r.dataset.table || null,
                            method: r.dataset.method,
                            durationMs: parseFloat(r.dataset.duration),
                            paramCount: parseInt(r.dataset.params, 10),
                            failed: r.dataset.failed === '1',
                            sql: r.dataset.sql
                        };
                    });

                    var blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
                    var url = URL.createObjectURL(blob);
                    var link = document.createElement('a');
                    link.href = url;
                    link.download = 'zara-queries-' + Date.now() + '.json';
                    document.body.appendChild(link);
                    link.click();
                    link.remove();
                    URL.revokeObjectURL(url);
                };
            })();
            </script>
            HTML;
    }
}
<?php

declare(strict_types=1);

namespace Zara\Core\Debug\Profiler\Panel;

use Zara\Container\Container;
use Zara\Core\Debug\Profiler\PanelInterface;
use Zara\Core\Debug\Profiler\Support\ClassLoadSource;
use Zara\Core\Debug\Profiler\Support\ClassLoadTracker;
use Zara\Core\Debug\Profiler\Support\Interfaces\ClassLoadTrackerInterface;
use Zara\Core\Debug\Profiler\Support\RouteSnapshotStore;

final class ClassUsagePanel implements PanelInterface
{
    private const DEFAULT_BUDGET = 200;

    private readonly ClassLoadTrackerInterface $tracker;

    // ParameterBag is a reserved/internal service (see ServiceRegistry::RESERVED_IDS)
    // and cannot be injected directly. The concrete Container class is safe to
    // type-hint here: Container::get() short-circuits both Container::class and
    // ContainerInterface::class via self::SELF_IDS before ever touching the
    // registry, and only the concrete class exposes getParameter()/hasParameter()
    // (they aren't part of the PSR ContainerInterface contract).
    public function __construct(private readonly Container $container)
    {
        $this->tracker = ClassLoadTracker::current();
    }

    public function getId(): string
    {
        return 'classes';
    }

    public function getLabel(): string
    {
        return 'Classes';
    }

    public function getIcon(): string
    {
        return '🧩';
    }

    public function getSummary(): string
    {
        $count = count($this->tracker->getRecords());
        return sprintf('%d class%s', $count, $count > 1 ? 'es' : '');
    }

    public function getBadgeColor(): string
    {
        $count = count($this->tracker->getRecords());
        $budget = $this->getBudget();

        return match (true) {
            $count > $budget => 'var(--zdb-err)',
            $count > (int) ($budget * 0.8) => 'var(--zdb-warn)',
            default => 'var(--zdb-ok)',
        };
    }

    private function getBudget(): int
    {
        if ($this->container->hasParameter('profiler.class_budget')) {
            return (int) $this->container->getParameter('profiler.class_budget');
        }

        return self::DEFAULT_BUDGET;
    }

    /**
     * Resolved lazily via the already-injected Container rather than as a
     * constructor dependency: not every app has Zara\Cache configured, and
     * this panel must keep working (minus cross-route comparison) for those
     * that don't, instead of failing to construct entirely.
     */
    private function resolveSnapshotStore(): ?RouteSnapshotStore
    {
        $id = \Psr\SimpleCache\CacheInterface::class;

        if (!$this->container->has($id)) {
            return null;
        }

        return new RouteSnapshotStore($this->container->get($id));
    }

    private function categorize(string $class): string
    {
        $class = ltrim($class, '\\');
        return match (true) {
            str_starts_with($class, 'App\\') => 'Application',
            str_starts_with($class, 'Zara\\') => 'Zara',
            default => 'ThirdParty',
        };
    }

    public function renderContent(): string
    {
        $records = $this->tracker->getRecords();

        if ($records === []) {
            return '<h3 style="margin-top:0;color:#6d4aff;">Classes used</h3>
                    <p style="color:#718096; font-style:italic;">No classes tracked.</p>';
        }

        // --- Precompute per-class memory delta, ordered by global load sequence ---
        $ordered = $records;
        usort($ordered, static fn($a, $b) => $a->order <=> $b->order);

        $deltaByClass = [];
        $previousMemory = null;
        foreach ($ordered as $record) {
            $deltaByClass[$record->class] = $previousMemory === null
                ? 0
                : $record->memoryDeltaBytes - $previousMemory;
            $previousMemory = $record->memoryDeltaBytes;
        }

        // --- Group by category (Application / Zara / ThirdParty) then by namespace ---
        $categories = ['Application' => [], 'Zara' => [], 'ThirdParty' => []];
        foreach ($records as $record) {
            $category = $this->categorize($record->class);
            $parts = explode('\\', ltrim($record->class, '\\'));
            $namespace = implode('\\', array_slice($parts, 0, 2)) ?: $record->class;
            $categories[$category][$namespace][] = $record;
        }

        $labels = [
            'Application' => 'Application (App\\*)',
            'Zara' => 'Framework (Zara\\*)',
            'ThirdParty' => 'Third-party libraries',
        ];
        $colors = [
            'Application' => '#4AFFA0',
            'Zara' => '#6d4aff',
            'ThirdParty' => '#718096',
        ];

        $count = count($records);
        $budget = $this->getBudget();
        $totalKo = array_sum(array_map(static fn($d) => max(0, $d), $deltaByClass)) / 1024;

        $html = sprintf('<h3 style="margin-top:0;color:#6d4aff;">Classes used (%d)</h3>', $count);
        $html .= '<p style="margin:0 0 10px; color:#718096; font-size:11px; font-style:italic;">
            Two independent measures: the classes below are those actually autoloaded on this request;
            the unresolved-services alert (if shown) compares every public service declared in <code>services.yaml</code>
            against those actually requested from the container — a service can show up here without it being a bug
            (an alternative driver, config unused on this particular route...).
        </p>';

        // --- Mini-summary line ---
        $html .= sprintf(
            '<div style="margin-bottom:8px; font-size:11px; color:#a0aec0;">
                <span style="color:#4AFFA0;">●</span> %d Application &nbsp;
                <span style="color:#6d4aff;">●</span> %d Framework &nbsp;
                <span style="color:#718096;">●</span> %d Third-party &nbsp;
                — estimated total weight: %.1f Ko
             </div>',
            array_sum(array_map('count', $categories['Application'])),
            array_sum(array_map('count', $categories['Zara'])),
            array_sum(array_map('count', $categories['ThirdParty'])),
            $totalKo
        );

        // --- Budget progress bar ---
        $ratio = min(1.0, $count / max(1, $budget));
        $barColor = match (true) {
            $count > $budget => '#ff5a5a',
            $count > (int) ($budget * 0.8) => '#ff9d4a',
            default => '#4AFFA0',
        };
        $html .= sprintf(
            '<div style="margin-bottom:10px;">
                <div style="height:6px; background:#111116; border-radius:3px; overflow:hidden;">
                    <div style="height:100%%; width:%d%%; background:%s;"></div>
                </div>
                <div style="font-size:10px; color:#718096; margin-top:2px;">%d / %d classes (budget %s)</div>
             </div>',
            (int) ($ratio * 100),
            $barColor,
            $count,
            $budget,
            $count > $budget ? 'exceeded' : 'ok'
        );

        // --- Budget alert ---
        if ($count > $budget) {
            $html .= sprintf(
                '<div style="margin-bottom:10px; padding:8px; background:#2a1a1a; border:1px solid #ff5a5a; border-radius:4px; color:#ff9d9d;">
                    ⚠ Budget exceeded: %d classes loaded for a budget of %d (parameter <code>profiler.class_budget</code>)
                 </div>',
                $count,
                $budget
            );
        }

        // --- Duplicate prototype resolution alert ---
        $duplicates = array_filter($this->tracker->getResolutionCounts(), static fn($n) => $n > 1);
        if ($duplicates !== []) {
            arsort($duplicates);
            $html .= '<div style="margin-bottom:10px; padding:8px; background:#2a1a1a; border:1px solid #ff9d4a; border-radius:4px;">
                <div style="color:#ff9d4a; font-weight:bold; margin-bottom:4px;">⚠ Services resolved multiple times (prototype scope?)</div>
                <div style="color:#a08060; font-size:10px; font-style:italic; margin-bottom:6px;">Each resolution builds a new instance — if this service does not need per-call state, switching it to singleton scope avoids the redundant work.</div>
                <div style="font-family:monospace; font-size:11px; color:#d0a080; max-height:100px; overflow-y:auto;">';
            foreach ($duplicates as $serviceId => $n) {
                $html .= sprintf('%s — %d times<br>', htmlspecialchars($serviceId), $n);
            }
            $html .= '</div></div>';
        }

        // --- Unresolved declared services alert ---
        $unresolved = $this->tracker->getUnresolvedDeclaredServices();
        if ($unresolved !== []) {
            $html .= sprintf(
                '<div style="margin-bottom:10px; padding:8px; background:#2a1a1a; border:1px solid #ff9d4a; border-radius:4px;">
                    <div style="color:#ff9d4a; font-weight:bold; margin-bottom:4px;">⚠ %d declared service%s never resolved on this route</div>
                    <div style="color:#a08060; font-size:10px; font-style:italic; margin-bottom:6px;">Not necessarily a problem: this includes alternative drivers/configs unused by this particular route (e.g. Sqlite when you use MySQL).</div>
                    <div style="font-family:monospace; font-size:11px; color:#d0a080; max-height:100px; overflow-y:auto;">%s</div>
                 </div>',
                count($unresolved),
                count($unresolved) > 1 ? 's' : '',
                implode('<br>', array_map('htmlspecialchars', $unresolved))
            );
        }

        // --- Filter input ---
        $html .= '<input type="text" id="zdb-classes-filter" placeholder="Filter by class name..."
            style="width:100%; box-sizing:border-box; margin-bottom:6px; padding:6px 8px; background:#111116; border:1px solid #2d2d3d; border-radius:4px; color:#e2e8f0; font-family:monospace; font-size:11px;"
            oninput="zdbFilterClasses(this.value)">';

        // --- Sort toggle buttons ---
        $html .= '<div style="display:flex; gap:6px; margin-bottom:8px;">';
        foreach ([
            'namespace' => 'Namespace',
            'order' => 'Load order',
            'time' => 'Time',
            'mem' => 'Memory',
        ] as $mode => $label) {
            $html .= sprintf(
                '<button onclick="zdbSortClasses(\'%s\')" style="background:#1a1a24; border:1px solid #2d2d3d; color:#a0aec0; padding:4px 8px; border-radius:4px; font-size:11px; cursor:pointer;">%s</button>',
                $mode,
                htmlspecialchars($label)
            );
        }
        $html .= '</div>';

        $html .= '<button id="zdb-export-btn" onclick="zdbExportClasses()" style="background:#1a1a24; border:1px solid #2d2d3d; color:#a0aec0; padding:4px 8px; border-radius:4px; font-size:11px; cursor:pointer; margin-bottom:8px;">📋 Export as JSON</button>';

        // --- Scrollable class list, single card ---
        $html .= '<div id="zdb-classes-list" style="max-height: 200px; overflow-y:auto; font-family:monospace; font-size:11px; background:#111116; padding:10px; border-radius:4px; border:1px solid #2d2d3d;">';

        foreach ($categories as $category => $namespaces) {
            if ($namespaces === []) {
                continue;
            }

            $total = array_sum(array_map('count', $namespaces));
            $html .= sprintf(
                '<div data-category="1" onclick="zdbToggleCategory(this)" style="padding:8px 0 4px; color:%s; font-weight:bold; border-bottom:1px solid #2d2d3d; margin-bottom:4px; cursor:pointer; user-select:none;">
                    <span class="zdb-cat-arrow">▾</span> %s — %d class%s
                 </div>',
                $colors[$category],
                $labels[$category],
                $total,
                $total > 1 ? 'es' : ''
            );

            ksort($namespaces);
            foreach ($namespaces as $namespace => $items) {
                $html .= sprintf(
                    '<div data-group="1" style="padding:4px 0 2px; color:#718096; font-weight:bold;">%s (%d)</div>',
                    htmlspecialchars($namespace),
                    count($items)
                );

                foreach ($items as $record) {
                    $deltaKo = $deltaByClass[$record->class] / 1024;

                    $html .= sprintf(
                        '<div data-class="%s" data-order="%d" data-time="%.3f" data-mem="%.3f" data-source="%s"
                            style="padding:4px 0; border-bottom:1px solid #1a1a24; color:#a0aec0; display:flex; justify-content:space-between; gap:12px;">
                            <span style="flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis;">%s</span>
                            <span style="color:#718096; white-space:nowrap;">+%.1f ms</span>
                            <span style="color:%s; white-space:nowrap;">%s%.1f Ko</span>
                            <span style="color:%s; white-space:nowrap;">%s</span>
                         </div>',
                        htmlspecialchars(strtolower($record->class)),
                        $record->order,
                        $record->timeOffsetMs,
                        $deltaKo,
                        $record->source->value,
                        htmlspecialchars($record->class),
                        $record->timeOffsetMs,
                        $deltaKo > 5 ? '#ff9d4a' : '#718096',
                        $deltaKo >= 0 ? '+' : '',
                        $deltaKo,
                        match ($record->source) {
                            ClassLoadSource::Autoload => '#718096',
                            ClassLoadSource::ContainerFresh => '#4AFFA0',
                            ClassLoadSource::ContainerReused => '#7C6DFF',
                        },
                        match ($record->source) {
                            ClassLoadSource::Autoload => 'direct instantiation',
                            ClassLoadSource::ContainerFresh => 'container (fresh instance)',
                            ClassLoadSource::ContainerReused => 'container (singleton)',
                        }
                    );
                }
            }
        }

        $html .= '</div>';

        // --- Client-side filter + sort (vanilla JS, no dependency) ---
        $html .= <<<'JS'
            <script>
            (function () {
                var list = document.getElementById('zdb-classes-list');
                if (list && !list.dataset.zdbInit) {
                    list.dataset.zdbInit = '1';
                    list._zdbOriginalOrder = Array.prototype.slice.call(list.children);
                }
            })();

            function zdbSortClasses(mode) {
                var list = document.getElementById('zdb-classes-list');
                if (!list || !list._zdbOriginalOrder) return;

                if (mode === 'namespace') {
                    list._zdbOriginalOrder.forEach(function (el) {
                        el.style.display = '';
                        list.appendChild(el);
                    });
                    return;
                }

                var rows = list._zdbOriginalOrder.filter(function (el) { return el.hasAttribute('data-class'); });
                var headers = list._zdbOriginalOrder.filter(function (el) { return !el.hasAttribute('data-class'); });
                headers.forEach(function (el) { el.style.display = 'none'; });

                rows.sort(function (a, b) {
                    var av = parseFloat(a.getAttribute('data-' + mode));
                    var bv = parseFloat(b.getAttribute('data-' + mode));
                    return mode === 'order' ? (av - bv) : (bv - av);
                });

                rows.forEach(function (el) {
                    el.style.display = '';
                    list.appendChild(el);
                });
            }

            function zdbFilterClasses(query) {
                query = query.toLowerCase();
                var list = document.getElementById('zdb-classes-list');
                if (!list) return;
                Array.prototype.forEach.call(list.children, function (el) {
                    if (el.hasAttribute('data-class')) {
                        el.style.display = el.getAttribute('data-class').indexOf(query) !== -1 ? '' : 'none';
                    }
                });
            }

            function zdbToggleCategory(header) {
                var collapsed = header.dataset.collapsed === '1';
                header.dataset.collapsed = collapsed ? '0' : '1';
                var arrow = header.querySelector('.zdb-cat-arrow');
                if (arrow) arrow.textContent = collapsed ? '▾' : '▸';

                var el = header.nextElementSibling;
                while (el && !el.hasAttribute('data-category')) {
                    el.style.display = collapsed ? '' : 'none';
                    el = el.nextElementSibling;
                }
            }

            function zdbExportClasses() {
                var list = document.getElementById('zdb-classes-list');
                if (!list) return;

                var rows = Array.prototype.filter.call(list.children, function (el) {
                    return el.hasAttribute('data-class');
                });

                var data = rows.map(function (el) {
                    return {
                        order: parseInt(el.getAttribute('data-order'), 10),
                        timeMs: parseFloat(el.getAttribute('data-time')),
                        memoryDeltaKo: parseFloat(el.getAttribute('data-mem')),
                        source: el.getAttribute('data-source'),
                        class: el.querySelector('span').textContent.trim()
                    };
                });

                var json = JSON.stringify(data, null, 2);

                if (navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext) {
                    navigator.clipboard.writeText(json).then(
                        function () { zdbExportFeedback(true); },
                        function () { zdbFallbackCopy(json); }
                    );
                } else {
                    zdbFallbackCopy(json);
                }
            }

            function zdbExportFeedback(success) {
                var btn = document.getElementById('zdb-export-btn');
                if (!btn) return;
                var original = btn.textContent;
                btn.textContent = success ? '✅ Copied!' : '⚠ Copy failed — see dialog';
                setTimeout(function () { btn.textContent = original; }, 1500);
            }

            function zdbFallbackCopy(text) {
                var ta = document.createElement('textarea');
                ta.value = text;
                // Kept on-screen (not opacity:0 / display:none) — some browsers
                // silently refuse select()/execCommand('copy') on elements they
                // consider "not really visible", which caused copies to fail
                // without any error, leaving the clipboard's previous content
                // untouched (looked like "copying something unrelated" from the
                // user's point of view, since nothing actually got overwritten).
                ta.style.position = 'fixed';
                ta.style.top = '10px';
                ta.style.left = '10px';
                ta.style.width = '2px';
                ta.style.height = '2px';
                ta.setAttribute('readonly', '');
                document.body.appendChild(ta);
                ta.focus();
                ta.select();
                ta.setSelectionRange(0, text.length); // required by iOS Safari

                var ok = false;
                try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
                document.body.removeChild(ta);

                if (ok) {
                    zdbExportFeedback(true);
                } else {
                    // Last resort: never fail silently. This guarantees the
                    // correct JSON reaches the user even if every clipboard API
                    // is blocked in this browser/context.
                    zdbExportFeedback(false);
                    window.prompt('Copy failed automatically — copy manually below:', text);
                }
            }
            </script>
            JS;

        $html .= $this->renderRouteComparison($records);

        return $html;
    }

    /**
     * Saves the current route's class list, then renders a client-side
     * comparison UI against every other route snapshot known so far — no
     * server round-trip: all snapshots are embedded as JSON and the diff is
     * computed in JS on select change, same approach as filter/sort/export.
     *
     * @param array<string, \Zara\Core\Debug\Profiler\Support\ClassLoadRecord> $records
     */
    private function renderRouteComparison(array $records): string
    {
        $store = $this->resolveSnapshotStore();

        if ($store === null) {
            return '<p style="margin-top:10px; color:#5a6080; font-size:11px; font-style:italic;">
                Cross-route comparison is unavailable — no PSR-16 cache service (Zara\\Cache) is configured for this app.
            </p>';
        }

        $currentRouteKey = RouteSnapshotStore::currentRouteKey();
        $currentClasses = array_values(array_map(static fn($r) => $r->class, $records));

        $store->save($currentRouteKey, $currentClasses);
        $snapshots = $store->getAllSnapshots();

        if (count($snapshots) < 2) {
            return '<p style="margin-top:10px; color:#5a6080; font-size:11px; font-style:italic;">
                Cross-route comparison needs at least 2 different routes visited — only this one has been seen so far. Browse another route, then come back.
            </p>';
        }

        $optionsHtml = '';
        foreach (array_keys($snapshots) as $routeKey) {
            $optionsHtml .= sprintf(
                '<option value="%s">%s (%d classes)</option>',
                htmlspecialchars($routeKey, ENT_QUOTES),
                htmlspecialchars($routeKey),
                count($snapshots[$routeKey])
            );
        }

        $snapshotsJson = json_encode(
            $snapshots,
            JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
                | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        if ($snapshotsJson === false) {
            return '<p style="margin-top:10px; color:#5a6080; font-size:11px; font-style:italic;">Route comparison data could not be encoded.</p>';
        }

        $html = <<<HTML
            <div style="margin-top:10px; padding:8px; background:#111116; border:1px solid #2d2d3d; border-radius:4px;">
                <div style="color:#7C6DFF; font-weight:bold; margin-bottom:6px; font-size:11px;">Compare two routes</div>
                <div style="display:flex; gap:8px; margin-bottom:8px;">
                    <select id="zdb-compare-a" onchange="zdbCompareRoutes()" style="flex:1; background:#1a1a24; color:#a0aec0; border:1px solid #2d2d3d; border-radius:4px; padding:4px; font-size:11px; font-family:monospace;">
                        {$optionsHtml}
                    </select>
                    <select id="zdb-compare-b" onchange="zdbCompareRoutes()" style="flex:1; background:#1a1a24; color:#a0aec0; border:1px solid #2d2d3d; border-radius:4px; padding:4px; font-size:11px; font-family:monospace;">
                        {$optionsHtml}
                    </select>
                </div>
                <div id="zdb-compare-result" style="font-family:monospace; font-size:11px;"></div>
            </div>
            <script type="application/json" id="zdb-route-snapshots">{$snapshotsJson}</script>
            <script>
            (function () {
                var selectA = document.getElementById('zdb-compare-a');
                var selectB = document.getElementById('zdb-compare-b');
                if (!selectA || !selectB) return;

                // Default: A = current route, B = the next different one in the list.
                selectA.value = {$this->jsonEncode($currentRouteKey)};
                for (var i = 0; i < selectB.options.length; i++) {
                    if (selectB.options[i].value !== selectA.value) {
                        selectB.value = selectB.options[i].value;
                        break;
                    }
                }

                window.zdbCompareRoutes();
            })();

            function zdbCompareRoutes() {
                var dataEl = document.getElementById('zdb-route-snapshots');
                var resultEl = document.getElementById('zdb-compare-result');
                var selectA = document.getElementById('zdb-compare-a');
                var selectB = document.getElementById('zdb-compare-b');
                if (!dataEl || !resultEl || !selectA || !selectB) return;

                var snapshots = JSON.parse(dataEl.textContent);
                var a = snapshots[selectA.value] || [];
                var b = snapshots[selectB.value] || [];
                var setB = {};
                b.forEach(function (c) { setB[c] = true; });
                var setA = {};
                a.forEach(function (c) { setA[c] = true; });

                var onlyA = a.filter(function (c) { return !setB[c]; }).sort();
                var onlyB = b.filter(function (c) { return !setA[c]; }).sort();
                var common = a.filter(function (c) { return setB[c]; }).length;

                function renderList(title, color, list) {
                    if (list.length === 0) {
                        return '<div style="margin-bottom:6px; color:#5a6080;">' + title + ': none</div>';
                    }
                    var rows = list.map(function (c) {
                        return '<div style="padding:1px 0; color:#a0aec0;">' + c.replace(/</g, '&lt;') + '</div>';
                    }).join('');
                    return '<div style="margin-bottom:6px;">' +
                        '<div style="color:' + color + '; font-weight:bold; margin-bottom:2px;">' + title + ' (' + list.length + ')</div>' +
                        '<div style="max-height:100px; overflow-y:auto; padding-left:8px;">' + rows + '</div>' +
                        '</div>';
                }

                resultEl.innerHTML =
                    '<div style="color:#718096; margin-bottom:6px;">' + common + ' classes in common</div>' +
                    renderList('Only in A', '#4AFFA0', onlyA) +
                    renderList('Only in B', '#ff9d4a', onlyB);
            }
            </script>
            HTML;

        return $html;
    }

    private function jsonEncode(string $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
                | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ) ?: '""';
    }
}

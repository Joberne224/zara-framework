<?php
declare(strict_types=1);

namespace Zara\Core\Debug\Profiler;

use Zara\Core\Debug\Profiler\Support\QueryTracker;
use Zara\Core\Config\Env;

/**
 * Generates the debug bar HTML to inject before </body>.
 *
 * The rendering is self-contained: CSS and JS are inline, no external
 * dependency. The bar can be minimized into a small draggable floating bubble,
 * and its panel height is resizable from the top edge.
 */
final class DebugBarRenderer
{
    public function __construct(private readonly Profiler $profiler) {}

    public function render(): string
    {
        if (!Env::isProfilerEnabled()) {
            return '';
        }

        $panels   = $this->profiler->getPanels();
        $tabs     = '';
        $contents = '';
        // array_values() first: Profiler::getPanels() can come back keyed by
        // service id (tag-based resolution via `!tagged:profiler.panel`)
        // rather than a plain numeric list, so $panels[0] is not reliable.
        $firstId  = (array_values($panels)[0] ?? null)?->getId() ?? '';

        // --- Logique de calcul pour le badge de la bulle ---
        $tracker       = QueryTracker::current();
        $repeatedCount = array_sum(array_map(
            static fn(int $count): int => max(0, $count - 1),
            $tracker->getRepeatedQueries()
        ));
        $queryCount    = count($tracker->getRecords());
        $totalMs       = $tracker->getTotalDurationMs();

        [$bubbleBadgeText, $bubbleBadgeBg] = match (true) {
            $repeatedCount > 0 => [sprintf('%d×', $repeatedCount), 'var(--zdb-err)'],
            $queryCount === 0  => ['0', 'var(--zdb-ok)'],
            $totalMs < 100.0   => [sprintf('%.0fms', $totalMs), 'var(--zdb-ok)'],
            $totalMs < 300.0   => [sprintf('%.0fms', $totalMs), 'var(--zdb-warn)'],
            default            => [sprintf('%.0fms', $totalMs), 'var(--zdb-err)'],
        };

        foreach ($panels as $panel) {
            $rawId   = $panel->getId();
            $id      = htmlspecialchars($rawId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $icon    = $panel->getIcon();
            $label   = htmlspecialchars($panel->getLabel(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $summary = htmlspecialchars($panel->getSummary(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $color   = $this->safeBadgeColor($panel->getBadgeColor());
            $active  = $rawId === $firstId ? ' zdb-tab-active' : '';

            $tabs .= <<<HTML
            <button class="zdb-tab{$active}" data-panel="{$id}" type="button">
                <span class="zdb-tab-icon">{$icon}</span>
                <span class="zdb-tab-label">{$label}</span>
                <span class="zdb-tab-badge" style="background:{$color}">{$summary}</span>
            </button>
            HTML;

            $display   = $rawId === $firstId ? 'flex' : 'none';
            $content   = $panel->renderContent();
            $contents .= <<<HTML
            <div class="zdb-panel" id="zdb-panel-{$id}" style="display:{$display}">
                <div class="zdb-panel-header">
                    <span class="zdb-panel-title"><span>{$icon}</span> <span>{$label}</span></span>
                </div>
                <div class="zdb-panel-body">{$content}</div>
            </div>
            HTML;
        }

        return $this->css() . <<<HTML
        <div id="zdb-root" class="zdb-root">
            <!-- Floating draggable bubble with dynamic status badge -->
            <button id="zdb-bubble" class="zdb-bubble" onclick="zdbExpand()" title="Drag / Click to open">
                <span>⚡</span>
                <span class="zdb-bubble-badge" style="background:{$bubbleBadgeBg}">{$bubbleBadgeText}</span>
            </button>

            <!-- Main bar container -->
            <div id="zdb-main" class="zdb-main">
                <!-- Transparent resize handle along the top edge -->
                <div id="zdb-resizer" class="zdb-resizer"></div>
                
                <div id="zdb-bar" class="zdb-bar">
                    <button class="zdb-brand" onclick="zdbTogglePanel()">
                        <span>⚡</span><span class="zdb-brand-label">Zara</span>
                    </button>
                    <div class="zdb-tabs">{$tabs}</div>
                    <button class="zdb-minimize" onclick="zdbMinimize()" title="Minimize to bubble">─</button>
                    <button class="zdb-close" onclick="zdbClosePanel()" title="Close debug panel" type="button">✕</button>
                </div>
                <div id="zdb-content" class="zdb-content" style="display:none">
                    {$contents}
                </div>
            </div>
        </div>
        HTML . $this->js();
    }

    private function safeBadgeColor(string $color): string
    {
        if (preg_match('/^var\\(--zdb-(?:ok|warn|err|blue|purple)\\)$/D', $color) === 1) {
            return $color;
        }

        return preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/D', $color) === 1
            ? $color
            : 'var(--zdb-blue)';
    }

    private function css(): string
    {
        return <<<CSS
        <style id="zdb-style">
        :root {
            --zdb-bg:      #0f1117;
            --zdb-bg2:     #1a1d27;
            --zdb-bg3:     #252836;
            --zdb-border:  #2d3250;
            --zdb-text:    #c8cee8;
            --zdb-muted:   #5a6080;
            --zdb-ok:      #22c55e;
            --zdb-warn:    #f59e0b;
            --zdb-err:     #ef4444;
            --zdb-blue:    #3b82f6;
            --zdb-purple:  #8b5cf6;
            --zdb-bar-h:   38px;
            --zdb-content-h: 300px;
            --zdb-z:       99999;
        }

        #zdb-root, #zdb-root * {
            box-sizing: border-box;
        }

        #zdb-root { 
            font-family: 'Cascadia Code', 'Fira Code', 'Consolas', monospace; 
            font-size: 12px; 
            line-height: 1;
        }

        #zdb-root button {
            font-family: inherit;
            border: none;
            outline: none;
            padding: 0;
            margin: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            line-height: 1;
        }

        /* Prevent text selection while dragging */
        .zdb-resizing, .zdb-resizing * { user-select: none !important; cursor: ns-resize !important; }

        /* Draggable floating bubble */
        .zdb-bubble {
            position: fixed;
            bottom: 14px;
            right: 14px;
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: var(--zdb-purple);
            color: #fff;
            border: 2px solid var(--zdb-border);
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.5);
            cursor: move;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            z-index: var(--zdb-z);
            user-select: none;
            touch-action: none;
            transition: transform .15s ease, background .15s ease;
        }
        /* The floating control is only visible while the bar is minimized. */
        #zdb-root:not(.zdb-minimized) .zdb-bubble { display: none !important; }
        #zdb-root.zdb-minimized .zdb-main { display: none !important; }
        .zdb-bubble:hover { transform: scale(1.1); background: #7c3aed; }
        .zdb-bubble:active { transform: scale(0.95); }

        /* Badge overlay on top-right of the bubble */
        .zdb-bubble-badge {
            position: absolute;
            top: -3px;
            right: -3px;
            padding: 2px 5px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 700;
            color: #fff;
            line-height: 1;
            border: 1px solid var(--zdb-border);
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.4);
            display: inline-flex;
            align-items: center;
        }

        /* Bar container */
        .zdb-main { 
            position: fixed; 
            bottom: 0; 
            left: 0; 
            right: 0; 
            z-index: var(--zdb-z); 
            box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.5);
        }

        /* Resizer handle tout en haut de zdb-main */
        .zdb-resizer {
            position: absolute;
            top: -4px;
            left: 0;
            right: 0;
            height: 7px;
            cursor: ns-resize;
            z-index: 100;
            background: transparent;
            transition: background .2s ease;
        }
        .zdb-resizer:hover, .zdb-resizing .zdb-resizer {
            background: var(--zdb-purple);
        }

        .zdb-bar { 
            display: flex; 
            align-items: center; 
            justify-content: space-between;
            height: var(--zdb-bar-h); 
            background: var(--zdb-bg); 
            border-top: 1px solid var(--zdb-border); 
            padding: 0 8px; 
            gap: 6px; 
            user-select: none; 
        }

        #zdb-root .zdb-brand { 
            display: inline-flex !important; 
            align-items: center !important; 
            justify-content: center !important;
            gap: 5px; 
            background: var(--zdb-purple); 
            color: #fff; 
            border-radius: 4px; 
            padding: 0 10px !important; 
            cursor: pointer; 
            font-weight: 700; 
            font-size: 12px; 
            white-space: nowrap; 
            flex: 0 0 auto;
            min-width: 68px;
            height: 26px;
            line-height: 1 !important;
        }
        .zdb-brand-label { letter-spacing:.05em; display: inline-flex; align-items: center; line-height: 1; }

        /* Onglets : Scrollbar horizontale fine visible et centrage vertical */
        .zdb-tabs { 
            display: flex; 
            align-items: center; 
            gap: 4px; 
            flex: 1; 
            min-width: 0; 
            overflow-x: auto; 
            overflow-y: hidden;
            height: 38px;
            scrollbar-width: thin;
            scrollbar-color: var(--zdb-border) transparent;
        }
        .zdb-tabs::-webkit-scrollbar {
            height: 4px;
        }
        .zdb-tabs::-webkit-scrollbar-track {
            background: transparent;
        }
        .zdb-tabs::-webkit-scrollbar-thumb {
            background: var(--zdb-border);
            border-radius: 2px;
        }
        .zdb-tabs::-webkit-scrollbar-thumb:hover {
            background: var(--zdb-purple);
        }

        #zdb-root .zdb-tab { 
            display: inline-flex !important; 
            align-items: center !important; 
            justify-content: center !important;
            gap: 5px; 
            background: none; 
            border: none; 
            color: var(--zdb-muted); 
            cursor: pointer; 
            padding: 0 8px !important; 
            border-radius: 4px; 
            white-space: nowrap; 
            transition: color .18s ease, background-color .18s ease, box-shadow .18s ease;
            flex-shrink: 0;
            height: 26px;
            line-height: 1 !important;
        }
        .zdb-tab > span { transform: translateY(1px); }
        .zdb-tab:hover {
            color:#fff;
            background:rgba(139,92,246,.16);
            box-shadow:inset 0 0 0 1px rgba(139,92,246,.32);
        }
        .zdb-tab-active {
            color:#fff;
            background:rgba(139,92,246,.22);
            box-shadow:inset 0 0 0 1px rgba(139,92,246,.42), inset 0 -2px 0 var(--zdb-purple);
        }
        .zdb-tab-active .zdb-tab-icon { filter:drop-shadow(0 0 4px rgba(167,139,250,.8)); }
        .zdb-tab:focus-visible { outline:2px solid var(--zdb-purple); outline-offset:-2px; }
        .zdb-tab-icon { font-size:13px; display: inline-flex; align-items: center; line-height: 1; }
        .zdb-tab-label { display: inline-flex; align-items: center; line-height: 1; }
        .zdb-tab-badge { 
            border-radius: 10px; 
            padding: 2px 7px; 
            color: #fff; 
            font-size: 10px; 
            font-weight: 700; 
            display: inline-flex; 
            align-items: center; 
            line-height: 1;
        }
        
        .zdb-minimize { 
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            background: none; 
            border: none; 
            color: var(--zdb-muted); 
            cursor: pointer; 
            font-size: 14px; 
            font-weight: bold; 
            flex-shrink: 0;
            width: 26px;
            height: 26px;
            border-radius: 4px;
            line-height: 1 !important;
        }
        .zdb-minimize:hover { color:var(--zdb-text); background: var(--zdb-bg3); }

        /* Content panel */
        .zdb-content { 
            position: relative; 
            background: var(--zdb-bg2); 
            border-top: 1px solid var(--zdb-border); 
            height: var(--zdb-content-h); 
            overflow: hidden; 
        }

        .zdb-panel {
            height:100%;
            display:flex;
            flex-direction:column;
            min-height:0;
            animation:zdb-panel-enter .18s ease-out both;
        }
        @keyframes zdb-panel-enter {
            from { opacity:0; transform:translateY(5px); }
            to { opacity:1; transform:translateY(0); }
        }
        .zdb-panel.is-leaving { animation:zdb-panel-exit .12s ease-in both; pointer-events:none; }
        @keyframes zdb-panel-exit {
            from { opacity:1; transform:translateY(0); }
            to { opacity:0; transform:translateY(-3px); }
        }
        @media (prefers-reduced-motion: reduce) {
            .zdb-tab, .zdb-panel { transition:none !important; animation:none !important; }
        }
        .zdb-panel-header { 
            display: flex; 
            align-items: center; 
            justify-content: space-between; 
            padding: 8px 14px; 
            background: var(--zdb-bg); 
            border-bottom: 1px solid var(--zdb-border); 
            flex-shrink: 0;
            height: 32px;
        }
        .zdb-panel-title { 
            display: inline-flex; 
            align-items: center; 
            gap: 6px; 
            font-weight: 700; 
            color: var(--zdb-text); 
            line-height: 1;
        }
        .zdb-panel-title > span { transform: translateY(1px); }
        .zdb-close { 
            display: none !important;
            align-items: center !important;
            justify-content: center !important;
            background: none; 
            border: none; 
            color: var(--zdb-muted); 
            cursor: pointer; 
            font-size: 13px; 
            flex-shrink: 0;
            width: 26px;
            height: 26px;
            border-radius: 4px;
            line-height: 1 !important;
        }
        #zdb-root.zdb-panel-open .zdb-close { display: inline-flex !important; }
        .zdb-close:hover { color:var(--zdb-text); background: var(--zdb-bg3); }

        /* Style de la scrollbar verticale pour le contenu des panneaux */
        .zdb-panel-body { 
            flex: 1; 
            overflow-y: auto; 
            padding: 12px 16px; 
            color: var(--zdb-text); 
            min-height: 0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            font-size: 13px;
            scrollbar-width: thin;
            scrollbar-color: var(--zdb-border) var(--zdb-bg);
            line-height: 1.5;
        }
        .zdb-panel-body h3 { margin: 0 0 12px; font-size: 15px; line-height: 1.35; font-weight: 650; }
        .zdb-panel-body h4 { margin: 14px 0 6px; font-size: 11px; line-height: 1.4; font-weight: 700; }
        .zdb-panel-body p { line-height: 1.5; }
        /* A panel's main scrolling list: grows to fill the panel body instead of a fixed max-height.
           Mark the list with class="zdb-fill" as a direct child of the panel content. */
        .zdb-panel-body:has(> .zdb-fill) { display: flex; flex-direction: column; }
        .zdb-panel-body > .zdb-fill { flex: 1; min-height: 80px; overflow-y: auto; }
        .zdb-panel-body::-webkit-scrollbar {
            width: 8px;
        }
        .zdb-panel-body::-webkit-scrollbar-track {
            background: var(--zdb-bg);
        }
        .zdb-panel-body::-webkit-scrollbar-thumb {
            background: var(--zdb-border);
            border-radius: 4px;
        }
        .zdb-panel-body::-webkit-scrollbar-thumb:hover {
            background: var(--zdb-purple);
        }

        /* Table */
        .zdb-table { width:100%; border-collapse:collapse; font-size:12px; line-height:1.45; }
        .zdb-table th { text-align:left; padding:8px 10px; color:var(--zdb-muted); font-size:10px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; border-bottom:1px solid var(--zdb-border); }
        .zdb-table td { padding:9px 10px; border-bottom:1px solid #1e2133; vertical-align:middle; }
        .zdb-table tr:last-child td { border-bottom:none; }
        .zdb-num { text-align:right; color:var(--zdb-blue); font-variant-numeric:tabular-nums; white-space:nowrap; }
        .zdb-log-table th:nth-child(2),.zdb-log-table td:nth-child(2),.zdb-log-table th:nth-child(3),.zdb-log-table td:nth-child(3) { text-align:center; }
        .zdb-mono { font-family:ui-monospace, 'Cascadia Code', 'Fira Code', Consolas, monospace; color:#a8b4e8; }
        .zdb-key  { color:var(--zdb-muted); white-space:nowrap; width:180px; }
        .zdb-request-table { table-layout:fixed; }
        .zdb-request-table td { vertical-align:middle; }
        .zdb-request-table td:first-child { width:180px; text-align:left; }
        .zdb-request-table td:last-child { text-align:left; overflow-wrap:anywhere; }
        .zdb-request-table .zdb-key { text-align:left; }
        .zdb-request-table .zdb-mono { line-height:1.5; }
        .zdb-database-table { max-width:720px; }
        .zdb-database-table td:first-child { width:190px; color:var(--zdb-muted); }
        .zdb-database-table td:last-child { font-family:ui-monospace, 'Cascadia Code', 'Fira Code', Consolas, monospace; color:#a8b4e8; overflow-wrap:anywhere; }
        .zdb-database-table .zdb-badge { font-family:inherit; }
        .zdb-database-note { margin-top:12px; color:var(--zdb-muted); font-size:11px; }
        .zdb-database-section-heading { margin:20px 0 10px !important; color:var(--zdb-text); }
        .zdb-database-cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(145px,1fr)); gap:9px; max-width:1100px; }
        .zdb-database-card { min-height:62px; padding:11px 13px; display:flex; flex-direction:column; justify-content:center; gap:7px; border:1px solid var(--zdb-border); border-radius:7px; background:linear-gradient(145deg,rgba(59,130,246,.08),rgba(26,29,39,.82)); }
        .zdb-database-card span { color:var(--zdb-muted); font-size:10px; font-weight:700; letter-spacing:.045em; text-transform:uppercase; }
        .zdb-database-card strong { color:#e2e8ff; font-size:14px; font-variant-numeric:tabular-nums; }
        .zdb-database-operations { max-width:480px; }
        .zdb-database-alert { display:inline-block; margin:12px 0 0; padding:8px 11px; border:1px solid rgba(239,68,68,.45); border-radius:6px; background:rgba(239,68,68,.1); color:#fca5a5; }
        .zdb-muted { color:var(--zdb-muted); font-style:italic; }
        .zdb-timeline { table-layout:fixed; font-variant-numeric:tabular-nums; }
        .zdb-timeline th:first-child, .zdb-timeline td:first-child { width:24%; text-align:left; }
        .zdb-timeline th:nth-child(2), .zdb-timeline td:nth-child(2),
        .zdb-timeline th:last-child, .zdb-timeline td:last-child { width:13%; }
        .zdb-timeline th:nth-child(2), .zdb-timeline td:nth-child(2),
        .zdb-timeline th:last-child, .zdb-timeline td:last-child { text-align:center; }
        .zdb-timeline th:nth-child(3), .zdb-timeline td:nth-child(3) { width:50%; }
        .zdb-timeline .zdb-timeline-event { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .zdb-timeline-total { background:rgba(139,92,246,.08); }
        .zdb-timeline-total td { color:var(--zdb-text); }
        .zdb-timeline-total .zdb-num { color:#b69aff; }
        .zdb-bar-track { height:7px; }
        .zdb-timeline-total-fill { width:100%; opacity:.35; }
        .zdb-timeline-empty { text-align:center; padding:20px !important; }
        .zdb-perf { min-width: 760px; color: var(--zdb-text); }
        .zdb-perf-summary { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:10px; margin:2px 0 20px; }
        .zdb-perf-card { min-height:58px; padding:11px 13px; border:1px solid var(--zdb-border); border-radius:7px; background:linear-gradient(145deg,rgba(139,92,246,.09),rgba(26,29,39,.8)); display:flex; flex-direction:column; justify-content:center; gap:7px; }
        .zdb-perf-card span { color:var(--zdb-muted); font-size:10px; font-weight:700; letter-spacing:.045em; text-transform:uppercase; }
        .zdb-perf-card strong { color:#e2e8ff; font-size:14px; font-weight:650; font-variant-numeric:tabular-nums; }
        .zdb-perf-heading { display:flex; align-items:baseline; justify-content:space-between; gap:16px; margin:0 2px 3px; }
        .zdb-perf-heading h3 { margin:0; }
        .zdb-perf-heading > span { color:var(--zdb-muted); font-size:10px; font-variant-numeric:tabular-nums; white-space:nowrap; }
        .zdb-perf-heading i { display:inline-block; width:32px; height:1px; margin:0 7px 3px; background:var(--zdb-border); }
        .zdb-perf-note { margin:6px 2px 12px; color:var(--zdb-muted); font-size:10px; }
        .zdb-perf-table { table-layout:fixed; font-variant-numeric:tabular-nums; }
        .zdb-perf-table th:first-child,.zdb-perf-table td:first-child { width:25%; }
        .zdb-perf-table th:nth-child(2),.zdb-perf-table td:nth-child(2) { width:12%; }
        .zdb-perf-table th:nth-child(3),.zdb-perf-table td:nth-child(3),.zdb-perf-table th:last-child,.zdb-perf-table td:last-child { width:12%; }
        .zdb-perf-table th:nth-child(4),.zdb-perf-table td:nth-child(4) { width:39%; }
        .zdb-perf-table th:nth-child(3),.zdb-perf-table td:nth-child(3),.zdb-perf-table th:last-child,.zdb-perf-table td:last-child { text-align:center; }
        .zdb-perf-label { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .zdb-perf-status { display:inline-block; margin-left:7px; padding:2px 5px; border-radius:4px; color:#fecaca; background:rgba(239,68,68,.16); font-size:9px; }
        .zdb-perf-track { position:relative; height:10px; overflow:hidden; border-radius:6px; background:rgba(7,9,14,.82); box-shadow:inset 0 1px 2px rgba(0,0,0,.35); }
        .zdb-perf-track span { position:absolute; top:1px; bottom:1px; min-width:2px; border-radius:5px; box-shadow:0 0 7px color-mix(in srgb,currentColor 30%,transparent); }
        .zdb-perf-empty { text-align:center; padding:24px !important; }
        /* Badges */
        .zdb-badge { display:inline-flex; align-items:center; border-radius:4px; padding:2px 6px; font-size:10px; font-weight:700; color:#fff; line-height:1; }
        .zdb-badge-ok   { background:var(--zdb-ok); }
        .zdb-badge-warn { background:var(--zdb-warn); color:#1a1a1a; }
        .zdb-badge-err  { background:var(--zdb-err); }
        .zdb-badge-blue { background:var(--zdb-blue); }
        /* Progress bar */
        .zdb-bar-track { background:var(--zdb-bg); border-radius:4px; height:8px; width:100%; overflow:hidden; }
        .zdb-bar-fill  { background:var(--zdb-purple); height:100%; border-radius:4px; min-width:2px; transition:width .3s; }
        /* JSON context */
        .zdb-ctx { font-size:11px; color:var(--zdb-muted); white-space:pre-wrap; max-height:80px; overflow:auto; margin-top:2px; }
        .zdb-section-title { margin:12px 0 4px; font-weight:700; color:var(--zdb-muted); text-transform:uppercase; font-size:10px; letter-spacing:.08em; }
        .zdb-label-row { display:flex; justify-content:space-between; margin-bottom:2px; }
        .zdb-mt { margin-top:12px; }
        </style>
        CSS;
    }

    private function js(): string
    {
        return <<<JS
        <script id="zdb-script">
        (function() {
            var panelOpen = false;
            var isDragging = false;
            var hasDragged = false;
            var startX, startY, initialLeft, initialTop;

            var bubble  = document.getElementById('zdb-bubble');
            var resizer = document.getElementById('zdb-resizer');
            var content = document.getElementById('zdb-content');
            var tabsContainer = document.querySelector('.zdb-tabs');

            // Storage may be disabled by browser privacy settings or sandboxed origins.
            function storageGet(key) {
                try { return window.localStorage.getItem(key); } catch (e) { return null; }
            }
            function storageSet(key, value) {
                try { window.localStorage.setItem(key, value); } catch (e) {}
            }

            // Enable horizontal tab scrolling with the mouse wheel.
            if (tabsContainer) {
                tabsContainer.addEventListener('wheel', function(e) {
                    if (e.deltaY !== 0) {
                        e.preventDefault();
                        tabsContainer.scrollLeft += e.deltaY;
                    }
                }, { passive: false });
            }

            // Restore saved panel content height
            var savedHeight = storageGet('zdb_content_h');
            if (savedHeight && content) {
                var height = Number(savedHeight);
                if (Number.isFinite(height)) {
                    content.style.height = Math.max(120, Math.min(window.innerHeight - 80, height)) + 'px';
                }
            }

            // Restore saved bubble position
            var savedPos = storageGet('zdb_bubble_pos');
            if (savedPos && bubble) {
                try {
                    var pos = JSON.parse(savedPos);
                    var savedX = Number(pos.x);
                    var savedY = Number(pos.y);
                    if (Number.isFinite(savedX) && Number.isFinite(savedY)) {
                        bubble.style.left = Math.max(0, Math.min(window.innerWidth - bubble.offsetWidth, savedX)) + 'px';
                        bubble.style.top = Math.max(0, Math.min(window.innerHeight - bubble.offsetHeight, savedY)) + 'px';
                    }
                    bubble.style.bottom = 'auto';
                    bubble.style.right = 'auto';
                } catch(e) {}
            }

            // Dragging logic for the floating bubble
            function onPointerDown(e) {
                if (e.button !== undefined && e.button !== 0) return;
                isDragging = true;
                hasDragged = false;
                var clientX = e.touches ? e.touches[0].clientX : e.clientX;
                var clientY = e.touches ? e.touches[0].clientY : e.clientY;

                var rect = bubble.getBoundingClientRect();
                startX = clientX;
                startY = clientY;
                initialLeft = rect.left;
                initialTop = rect.top;

                document.addEventListener('mousemove', onPointerMove, { passive: false });
                document.addEventListener('mouseup', onPointerUp);
                document.addEventListener('touchmove', onPointerMove, { passive: false });
                document.addEventListener('touchend', onPointerUp);
            }

            function onPointerMove(e) {
                if (!isDragging) return;
                var clientX = e.touches ? e.touches[0].clientX : e.clientX;
                var clientY = e.touches ? e.touches[0].clientY : e.clientY;
                var dx = clientX - startX;
                var dy = clientY - startY;

                if (Math.abs(dx) > 3 || Math.abs(dy) > 3) {
                    hasDragged = true;
                }

                if (hasDragged) {
                    if (e.cancelable) e.preventDefault();
                    var newLeft = Math.max(0, Math.min(window.innerWidth - bubble.offsetWidth, initialLeft + dx));
                    var newTop = Math.max(0, Math.min(window.innerHeight - bubble.offsetHeight, initialTop + dy));

                    bubble.style.left = newLeft + 'px';
                    bubble.style.top = newTop + 'px';
                    bubble.style.bottom = 'auto';
                    bubble.style.right = 'auto';
                }
            }

            function onPointerUp() {
                if (!isDragging) return;
                isDragging = false;
                document.removeEventListener('mousemove', onPointerMove);
                document.removeEventListener('mouseup', onPointerUp);
                document.removeEventListener('touchmove', onPointerMove);
                document.removeEventListener('touchend', onPointerUp);

                if (hasDragged) {
                    var rect = bubble.getBoundingClientRect();
                    storageSet('zdb_bubble_pos', JSON.stringify({ x: rect.left, y: rect.top }));
                }
            }

            if (bubble) {
                bubble.addEventListener('mousedown', onPointerDown);
                bubble.addEventListener('touchstart', onPointerDown, { passive: true });
                // Browsers emit a click after a drag ends. Swallow only that
                // synthetic click; a later deliberate click must still open
                // the bar.
                bubble.addEventListener('click', function(e) {
                    if (!hasDragged) return;
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    hasDragged = false;
                }, true);
            }

            // --- Vertical Resize logic from the top edge of DebugBar ---
            var resizeStartY, resizeStartH;

            function onResizeDown(e) {
                if (e.button !== undefined && e.button !== 0) return;

                if (content.style.display === 'none') {
                    zdbTogglePanel();
                }

                resizeStartY = e.touches ? e.touches[0].clientY : e.clientY;
                resizeStartH = content.offsetHeight;

                document.documentElement.classList.add('zdb-resizing');
                document.addEventListener('mousemove', onResizeMove, { passive: false });
                document.addEventListener('mouseup', onResizeUp);
                document.addEventListener('touchmove', onResizeMove, { passive: false });
                document.addEventListener('touchend', onResizeUp);
            }

            function onResizeMove(e) {
                if (e.cancelable) e.preventDefault();
                var clientY = e.touches ? e.touches[0].clientY : e.clientY;
                var deltaY = resizeStartY - clientY;
                var newHeight = Math.max(120, Math.min(window.innerHeight - 80, resizeStartH + deltaY));

                content.style.height = newHeight + 'px';
            }

            function onResizeUp() {
                document.documentElement.classList.remove('zdb-resizing');
                document.removeEventListener('mousemove', onResizeMove);
                document.removeEventListener('mouseup', onResizeUp);
                document.removeEventListener('touchmove', onResizeMove);
                document.removeEventListener('touchend', onResizeUp);

                storageSet('zdb_content_h', content.offsetHeight);
            }

            if (resizer) {
                resizer.addEventListener('mousedown', onResizeDown);
                resizer.addEventListener('touchstart', onResizeDown, { passive: true });
            }

            // Global control functions
            window.zdbMinimize = function() {
                var root = document.getElementById('zdb-root');
                if (!root) return;
                root.classList.add('zdb-minimized');
                root.querySelector('.zdb-main').style.display = 'none';
                root.querySelector('.zdb-bubble').style.display = 'flex';
                storageSet('zdb_minimized', 'true');
            };

            window.zdbExpand = function() {
                if (hasDragged) return;
                var root = document.getElementById('zdb-root');
                if (!root) return;
                root.classList.remove('zdb-minimized');
                root.querySelector('.zdb-bubble').style.display = 'none';
                root.querySelector('.zdb-main').style.display = 'block';
                storageSet('zdb_minimized', 'false');
            };

            window.zdbTogglePanel = function() {
                panelOpen = !panelOpen;
                content.style.display = panelOpen ? 'block' : 'none';
                document.getElementById('zdb-root').classList.toggle('zdb-panel-open', panelOpen);
            };

            window.zdbClosePanel = function() {
                panelOpen = false;
                content.style.display = 'none';
                document.getElementById('zdb-root').classList.remove('zdb-panel-open');
            };

            window.zdbOpen = function(id) {
                var el = document.getElementById('zdb-panel-' + id);
                document.querySelectorAll('.zdb-panel').forEach(function(p) {
                    if (p === el) {
                        p.classList.remove('is-leaving');
                        p.style.display = 'flex';
                        return;
                    }
                    if (p.style.display !== 'none') {
                        p.classList.add('is-leaving');
                        window.setTimeout(function() {
                            if (p.classList.contains('is-leaving')) {
                                p.style.display = 'none';
                                p.classList.remove('is-leaving');
                            }
                        }, 130);
                    }
                });
                document.querySelectorAll('.zdb-tab').forEach(function(t) {
                    t.classList.toggle('zdb-tab-active', t.dataset.panel === id);
                });
                panelOpen = true;
                content.style.display = 'block';
                document.getElementById('zdb-root').classList.add('zdb-panel-open');
            };

            if (tabsContainer) {
                tabsContainer.addEventListener('click', function(e) {
                    var tab = e.target.closest('.zdb-tab');
                    if (tab && tabsContainer.contains(tab)) {
                        window.zdbOpen(tab.dataset.panel || '');
                    }
                });
            }

            // Restore minimized state after page reload
            if (storageGet('zdb_minimized') === 'true') {
                zdbMinimize();
            }

            // Shortcut: Alt+D toggles between bubble and full bar
            document.addEventListener('keydown', function(e) {
                if (e.altKey && e.key.toLowerCase() === 'd') {
                    var main = document.getElementById('zdb-main');
                    if (main.style.display === 'none') {
                        zdbExpand();
                    } else {
                        zdbMinimize();
                    }
                }
            });
        })();
        </script>
        JS;
    }
}

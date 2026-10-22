<?php
declare(strict_types=1);

namespace Zara\Core\Debug\Profiler;

/**
 * Contract for a profiler panel.
 *
 * Each implementation collects a specific type of data (timing, memory,
 * logs, HTTP requests, etc.) and renders it as HTML for the debug bar.
 */
interface PanelInterface
{
    /**
     * Unique panel identifier (used as a CSS id).
     * Ex: 'timeline', 'memory', 'request', 'logs'
     */
    public function getId(): string;

    /**
     * Label displayed in the tab.
     */
    public function getLabel(): string;

    /**
     * SVG icon or emoji displayed in the tab.
     */
    public function getIcon(): string;

    /**
     * Summary value displayed directly in the tab bar.
     * Ex: '42ms', '8.2MB', '3 logs'
     */
    public function getSummary(): string;

    /**
     * CSS color of the summary badge for the current state.
     * Ex: 'var(--zdb-ok)' ou 'var(--zdb-warn)' ou 'var(--zdb-err)'
     */
    public function getBadgeColor(): string;

    /**
     * Panel HTML content (displayed when the tab is opened).
     */
    public function renderContent(): string;
}

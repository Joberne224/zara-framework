<?php

declare(strict_types=1);

namespace Zara\Core\Debug\Profiler\Panel;

use Zara\Core\Debug\Profiler\PanelInterface;

final class IncludedFilesPanel implements PanelInterface
{
    private array $files = [];
    private float $totalSizeKb = 0.0;

    public function __construct()
    {
        $this->collect();
    }

    private function collect(): void
    {
        $this->files = get_included_files();
        $bytes = 0;

        foreach ($this->files as $file) {
            if (is_file($file) && is_readable($file)) {
                $size = @filesize($file);
                if ($size !== false) {
                    $bytes += $size;
                }
            }
        }

        $this->totalSizeKb = $bytes / 1024;
    }

    public function getId(): string
    {
        return 'files';
    }

    public function getLabel(): string
    {
        return 'Included files';
    }

    public function getIcon(): string
    {
        return '📁';
    }

    public function getSummary(): string
    {
        $count = count($this->files);
        return sprintf('%d file%s (%.1f KB)', $count, $count > 1 ? 's' : '', $this->totalSizeKb);
    }

    public function getBadgeColor(): string
    {
        return 'var(--zdb-ok)';
    }

    public function renderContent(): string
    {
        $count = count($this->files);

        if ($count === 0) {
            return '<h3 style="margin-top:0;color:#6d4aff;">Included PHP files</h3>
                    <p style="color:#718096; font-style:italic;">No included files detected.</p>';
        }

        $html = sprintf(
            '<h3 style="margin-top:0;color:#6d4aff;">Included PHP files (%d file%s - Total: %.1f KB)</h3>',
            $count,
            $count > 1 ? 's' : '',
            $this->totalSizeKb
        );

        $html .= '<div class="zdb-fill" style="font-family:monospace; font-size:11px; background:#111116; padding:10px; border-radius:4px; border:1px solid #2d2d3d;">';

        foreach ($this->files as $index => $file) {
            $html .= sprintf(
                '<div style="padding:4px 0; border-bottom:1px solid #1a1a24; color:#a0aec0;">
                    <span style="color:#718096; margin-right:10px;">#%02d</span>%s
                 </div>',
                $index + 1,
                htmlspecialchars($file)
            );
        }

        $html .= '</div>';
        return $html;
    }
}
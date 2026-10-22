<?php

declare(strict_types=1);

namespace Zara\Core\Debug\Profiler\Panel;

use Zara\Core\Debug\Profiler\PanelInterface;

/**
 * Zara framework configuration editor panel.
 *
 * Displays an HTML/CSS/JS interface for viewing framework settings,
 * paths, database settings, routing, and related configuration.
 *
 * Read-only by default. The edit toggle only changes the displayed values;
 * it does not persist configuration changes.
 */
final class ConfigEditorPanel implements PanelInterface
{
    private array $config = [];
    private array $env = [];
    private array $phpInfo = [];
    private string $configPath;

    public function __construct(
        private readonly string $basePath,
    ) {
        $this->configPath = $this->basePath . '/configuration/services.yaml';
        $this->collectData();
    }

    private function collectData(): void
    {
        $this->config = [
            'database' => [
                'host' => $_ENV['DB_HOST'] ?? 'localhost',
                'name' => $_ENV['DB_NAME'] ?? 'project',
                'user' => $_ENV['DB_USER'] ?? 'root',
                'password' => !empty($_ENV['DB_PASS']) ? '••••••••' : '(vide)',
            ],
            'app' => [
                'debug' => $_ENV['APP_DEBUG'] ?? 'true',
                'env' => $_ENV['APP_ENV'] ?? 'dev',
                'url' => $_ENV['APP_URL'] ?? 'http://localhost',
            ],
            'paths' => [
                'base' => $this->basePath,
                'public' => $this->basePath . '/public',
                'storage' => $this->basePath . '/storage',
                'cache' => $this->basePath . '/storage/cache',
                'logs' => $this->basePath . '/storage/logs',
                'views' => $this->basePath . '/configuration/templates',
                'routes' => $this->basePath . '/configuration/routes.php',
            ],
        ];

        $this->env = array_filter(
            $_ENV,
            fn(string $key) => $this->isSensitive($key) === false,
            ARRAY_FILTER_USE_KEY
        );

        $this->phpInfo = [
            'version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'os' => PHP_OS,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'display_errors' => ini_get('display_errors'),
            'timezone' => date_default_timezone_get(),
            'extensions' => get_loaded_extensions(),
        ];
    }

    private function isSensitive(string $key): bool
    {
        $key = strtolower($key);
        foreach (['secret', 'password', 'pass', 'token', 'key', 'credential'] as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function getId(): string
    {
        return 'config';
    }

    public function getLabel(): string
    {
        return 'Configuration';
    }

    public function getIcon(): string
    {
        return '⚙️';
    }

    public function getSummary(): string
    {
        $env = $this->config['app']['env'] ?? 'unknown';
        return ucfirst($env);
    }

    public function getBadgeColor(): string
    {
        return ($this->config['app']['env'] ?? 'dev') === 'prod'
            ? 'var(--zdb-err)'
            : 'var(--zdb-ok)';
    }

    public function renderContent(): string
    {
        $configJson = htmlspecialchars(json_encode($this->config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $envJson = htmlspecialchars(json_encode($this->env, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $extCount = count($this->phpInfo['extensions']);
        $isWritable = is_writable($this->configPath);
        $writeStatus = $isWritable
            ? '<span style="color:#34c759;">✓ File is writable</span>'
            : '<span style="color:#ffcc00;">⚠ File is read-only</span>';

        $extensionsHtml = '';
        foreach (array_chunk($this->phpInfo['extensions'], 6) as $chunk) {
            $extensionsHtml .= '<div style="display:flex; gap:4px; margin-bottom:4px;">';
            foreach ($chunk as $ext) {
                $extensionsHtml .= sprintf(
                    '<span style="background:#1a1a24; color:#a0aec0; padding:2px 8px; border-radius:3px; font-size:10px; border:1px solid #2d2d3d;">%s</span>',
                    htmlspecialchars($ext)
                );
            }
            $extensionsHtml .= '</div>';
        }

        $pathsHtml = '';
        foreach ($this->config['paths'] as $label => $path) {
            $exists = is_dir($path) || is_file($path);
            $status = $exists
                ? '<span style="display:inline-flex; align-items:center; gap:4px; margin-left:8px; font-size:11px; color:#34c759;">✓ Exists</span>'
                : '<span style="display:inline-flex; align-items:center; gap:4px; margin-left:8px; font-size:11px; color:#ff3b30;">✗ Not found</span>';
            $pathsHtml .= <<<HTML
            <div class="zdb-field">
                <span class="zdb-field-label">{$label}</span>
                <input class="zdb-field-input" value="{$path}" readonly data-zdb-field>
                {$status}
            </div>
HTML;
        }

        $html = <<<HTML
<style>
    .zdb-config-wrap {
        font-family: system-ui, -apple-system, sans-serif;
        color: #e2e8f0;
        display: flex;
        flex-direction: column;
        height: 100%;
    }
    .zdb-config-wrap * { box-sizing: border-box; }

    .zdb-toolbar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 12px;
        padding-bottom: 12px;
        border-bottom: 1px solid #2d2d3d;
        flex-shrink: 0;
    }

    .zdb-tabs {
        display: flex;
        gap: 2px;
        background: #111116;
        padding: 4px;
        border-radius: 6px;
        margin-bottom: 12px;
        border: 1px solid #2d2d3d;
        flex-shrink: 0;
    }
    .zdb-tab {
        flex: 1;
        padding: 6px 10px;
        background: transparent;
        border: none;
        color: #718096;
        cursor: pointer;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 600;
        transition: all 0.15s;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .zdb-tab:hover { color: #fff; background: #1a1a24; }
    .zdb-tab.zdb-active { color: #fff; background: #6d4aff; }

    .zdb-tab-panel {
        display: none;
        flex: 1;
        overflow-y: auto;
        min-height: 0;
        padding-right: 8px;
    }
    .zdb-tab-panel.zdb-active { display: block; }

    .zdb-tab-panel::-webkit-scrollbar { width: 6px; }
    .zdb-tab-panel::-webkit-scrollbar-track { background: #111116; }
    .zdb-tab-panel::-webkit-scrollbar-thumb { background: #2d2d3d; border-radius: 3px; }
    .zdb-tab-panel::-webkit-scrollbar-thumb:hover { background: #6d4aff; }

    .zdb-section {
        background: #111116;
        border: 1px solid #2d2d3d;
        border-radius: 8px;
        padding: 16px;
        margin-bottom: 12px;
    }
    .zdb-section-title {
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: #6d4aff;
        margin: 0 0 12px 0;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .zdb-field {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 8px 0;
        border-bottom: 1px solid #1a1a24;
    }
    .zdb-field:last-child { border-bottom: none; }
    .zdb-field-label {
        width: 160px;
        font-size: 12px;
        color: #718096;
        font-weight: 500;
        flex-shrink: 0;
    }
    .zdb-field-input {
        flex: 1;
        background: #0c0c12;
        border: 1px solid #2d2d3d;
        border-radius: 4px;
        padding: 6px 10px;
        color: #e2e8f0;
        font-family: 'SF Mono', Monaco, monospace;
        font-size: 12px;
        outline: none;
        transition: border-color 0.15s;
    }
    .zdb-field-input:focus {
        border-color: #6d4aff;
        box-shadow: 0 0 0 2px rgba(109, 74, 255, 0.15);
    }
    .zdb-field-input:read-only {
        background: #1a1a24;
        color: #718096;
        cursor: not-allowed;
    }

    .zdb-toggle {
        display: flex;
        align-items: center;
        gap: 8px;
        cursor: pointer;
        padding: 4px 12px;
        background: #1a1a24;
        border-radius: 4px;
        border: 1px solid #2d2d3d;
        font-size: 12px;
        color: #a0aec0;
        transition: all 0.15s;
    }
    .zdb-toggle:hover { border-color: #6d4aff; color: #fff; }
    .zdb-toggle input { display: none; }
    .zdb-toggle-slider {
        width: 32px;
        height: 18px;
        background: #2d2d3d;
        border-radius: 9px;
        position: relative;
        transition: background 0.2s;
    }
    .zdb-toggle-slider::after {
        content: '';
        position: absolute;
        width: 14px;
        height: 14px;
        background: #fff;
        border-radius: 50%;
        top: 2px;
        left: 2px;
        transition: transform 0.2s;
    }
    .zdb-toggle input:checked + .zdb-toggle-slider { background: #6d4aff; }
    .zdb-toggle input:checked + .zdb-toggle-slider::after { transform: translateX(14px); }

    .zdb-badge {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 3px;
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .zdb-badge-ok { background: rgba(52, 199, 89, 0.15); color: #34c759; }
    .zdb-badge-warn { background: rgba(255, 204, 0, 0.15); color: #ffcc00; }

    .zdb-btn {
        background: #6d4aff;
        color: #fff;
        border: none;
        border-radius: 4px;
        padding: 6px 14px;
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.15s;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .zdb-btn:hover { background: #7c5cff; transform: translateY(-1px); }
    .zdb-btn-secondary {
        background: #1a1a24;
        color: #a0aec0;
        border: 1px solid #2d2d3d;
    }
    .zdb-btn-secondary:hover { background: #222230; color: #fff; }
    .zdb-btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
        transform: none !important;
    }

    .zdb-json-editor {
        background: #0c0c12;
        border: 1px solid #2d2d3d;
        border-radius: 6px;
        padding: 12px;
        font-family: 'SF Mono', Monaco, monospace;
        font-size: 11px;
        line-height: 1.6;
        color: #a0aec0;
        white-space: pre-wrap;
        word-break: break-word;
        min-height: 120px;
        max-height: 300px;
        height: 200px;
        resize: vertical;
        width: 100%;
        outline: none;
        overflow-y: auto;
    }
    .zdb-json-editor:focus { border-color: #6d4aff; }
    .zdb-json-editor:read-only { background: #111116; cursor: default; }

    .zdb-alert {
        padding: 10px 14px;
        border-radius: 6px;
        font-size: 12px;
        margin-bottom: 12px;
        display: none;
    }
    .zdb-alert-success {
        background: rgba(52, 199, 89, 0.1);
        border: 1px solid rgba(52, 199, 89, 0.3);
        color: #34c759;
    }
    .zdb-alert-error {
        background: rgba(255, 59, 48, 0.1);
        border: 1px solid rgba(255, 59, 48, 0.3);
        color: #ff3b30;
    }
</style>

<div class="zdb-config-wrap" id="zdb-config-{$this->getId()}">
    <div class="zdb-toolbar">
        <h3 style="margin:0; color:#6d4aff; font-size:16px;">⚙️ Zara Configuration</h3>
        <div style="display:flex; align-items:center; gap:12px;">
            <span style="font-size:11px; color:#718096;">{$writeStatus}</span>
            <label class="zdb-toggle" onclick="zdbToggleEdit('{$this->getId()}')">
                <input type="checkbox" id="zdb-edit-toggle-{$this->getId()}">
                <span class="zdb-toggle-slider"></span>
                <span>Enable editing</span>
            </label>
        </div>
    </div>

    <div class="zdb-alert zdb-alert-success" id="zdb-alert-success-{$this->getId()}">✅ Configuration saved successfully.</div>
    <div class="zdb-alert zdb-alert-error" id="zdb-alert-error-{$this->getId()}">❌ Failed to save configuration.</div>

    <div class="zdb-tabs">
        <button class="zdb-tab zdb-active" onclick="zdbSwitchTab('{$this->getId()}', 'app', this)">Application</button>
        <button class="zdb-tab" onclick="zdbSwitchTab('{$this->getId()}', 'database', this)">Database</button>
        <button class="zdb-tab" onclick="zdbSwitchTab('{$this->getId()}', 'paths', this)">Paths</button>
        <button class="zdb-tab" onclick="zdbSwitchTab('{$this->getId()}', 'php', this)">PHP</button>
        <button class="zdb-tab" onclick="zdbSwitchTab('{$this->getId()}', 'env', this)">Environnement</button>
        <button class="zdb-tab" onclick="zdbSwitchTab('{$this->getId()}', 'raw', this)">Raw JSON</button>
    </div>

    <!-- TAB: Application -->
    <div class="zdb-tab-panel zdb-active" id="zdb-tab-{$this->getId()}-app">
        <div class="zdb-section">
            <div class="zdb-section-title">🚀 General settings</div>
            <div class="zdb-field">
                <span class="zdb-field-label">Environnement</span>
                <input class="zdb-field-input" value="{$this->config['app']['env']}" readonly data-zdb-field data-key="app.env">
                <span class="zdb-badge zdb-badge-ok">{$this->config['app']['env']}</span>
            </div>
            <div class="zdb-field">
                <span class="zdb-field-label">Debug</span>
                <input class="zdb-field-input" value="{$this->config['app']['debug']}" readonly data-zdb-field data-key="app.debug">
            </div>
            <div class="zdb-field">
                <span class="zdb-field-label">URL de l'app</span>
                <input class="zdb-field-input" value="{$this->config['app']['url']}" readonly data-zdb-field data-key="app.url">
            </div>
        </div>
        <div class="zdb-section">
            <div class="zdb-section-title">🛡️ Middlewares</div>
            <div style="display:flex; flex-wrap:wrap; gap:4px;">
                <span class="zdb-badge zdb-badge-ok">ProfilerMiddleware</span>
                <span class="zdb-badge zdb-badge-ok">CorsMiddleware</span>
                <span class="zdb-badge zdb-badge-warn">AuthMiddleware</span>
                <span class="zdb-badge zdb-badge-warn">CsrfMiddleware</span>
            </div>
        </div>
    </div>

    <!-- TAB: Database -->
    <div class="zdb-tab-panel" id="zdb-tab-{$this->getId()}-database">
        <div class="zdb-section">
            <div class="zdb-section-title">🗄️ Database connection</div>
            <div class="zdb-field">
                <span class="zdb-field-label">Host</span>
                <input class="zdb-field-input" value="{$this->config['database']['host']}" readonly data-zdb-field data-key="database.host">
            </div>
            <div class="zdb-field">
                <span class="zdb-field-label">Database name</span>
                <input class="zdb-field-input" value="{$this->config['database']['name']}" readonly data-zdb-field data-key="database.name">
            </div>
            <div class="zdb-field">
                <span class="zdb-field-label">Username</span>
                <input class="zdb-field-input" value="{$this->config['database']['user']}" readonly data-zdb-field data-key="database.user">
            </div>
            <div class="zdb-field">
                <span class="zdb-field-label">Password</span>
                <input class="zdb-field-input" type="password" value="{$this->config['database']['password']}" readonly data-zdb-field data-key="database.password">
            </div>
        </div>
    </div>

    <!-- TAB: Paths -->
    <div class="zdb-tab-panel" id="zdb-tab-{$this->getId()}-paths">
        <div class="zdb-section">
            <div class="zdb-section-title">📁 Project paths</div>
            {$pathsHtml}
        </div>
    </div>

    <!-- TAB: PHP -->
    <div class="zdb-tab-panel" id="zdb-tab-{$this->getId()}-php">
        <div class="zdb-section">
            <div class="zdb-section-title">🐘 Informations PHP</div>
            <div class="zdb-field"><span class="zdb-field-label">Version</span><input class="zdb-field-input" value="{$this->phpInfo['version']}" readonly data-zdb-field></div>
            <div class="zdb-field"><span class="zdb-field-label">SAPI</span><input class="zdb-field-input" value="{$this->phpInfo['sapi']}" readonly data-zdb-field></div>
            <div class="zdb-field"><span class="zdb-field-label">OS</span><input class="zdb-field-input" value="{$this->phpInfo['os']}" readonly data-zdb-field></div>
            <div class="zdb-field"><span class="zdb-field-label">Timezone</span><input class="zdb-field-input" value="{$this->phpInfo['timezone']}" readonly data-zdb-field></div>
        </div>
        <div class="zdb-section">
            <div class="zdb-section-title">📊 Limits</div>
            <div class="zdb-field"><span class="zdb-field-label">Memory limit</span><input class="zdb-field-input" value="{$this->phpInfo['memory_limit']}" readonly data-zdb-field></div>
            <div class="zdb-field"><span class="zdb-field-label">Max execution</span><input class="zdb-field-input" value="{$this->phpInfo['max_execution_time']}s" readonly data-zdb-field></div>
            <div class="zdb-field"><span class="zdb-field-label">Upload max</span><input class="zdb-field-input" value="{$this->phpInfo['upload_max_filesize']}" readonly data-zdb-field></div>
            <div class="zdb-field"><span class="zdb-field-label">Post max</span><input class="zdb-field-input" value="{$this->phpInfo['post_max_size']}" readonly data-zdb-field></div>
            <div class="zdb-field"><span class="zdb-field-label">Display errors</span><input class="zdb-field-input" value="{$this->phpInfo['display_errors']}" readonly data-zdb-field></div>
        </div>
        <div class="zdb-section">
            <div class="zdb-section-title">🔌 Loaded extensions ({$extCount})</div>
            {$extensionsHtml}
        </div>
    </div>

    <!-- TAB: Environment -->
    <div class="zdb-tab-panel" id="zdb-tab-{$this->getId()}-env">
        <div class="zdb-section">
            <div class="zdb-section-title">🌍 Variables d'environnement</div>
            <textarea class="zdb-json-editor" readonly data-zdb-field>{$envJson}</textarea>
        </div>
    </div>

    <!-- TAB: Raw JSON -->
    <div class="zdb-tab-panel" id="zdb-tab-{$this->getId()}-raw">
        <div class="zdb-section">
            <div class="zdb-section-title">📄 Configuration brute (JSON)</div>
            <textarea class="zdb-json-editor" id="zdb-raw-json-{$this->getId()}" readonly data-zdb-field>{$configJson}</textarea>
            <div style="display:flex; gap:8px; margin-top:12px;">
                <button class="zdb-btn" onclick="zdbCopyConfig('{$this->getId()}')">📋 Copier</button>
                <button class="zdb-btn zdb-btn-secondary" onclick="zdbDownloadConfig('{$this->getId()}')">💾 Download</button>
                <button class="zdb-btn" id="zdb-save-btn-{$this->getId()}" onclick="zdbSaveConfig('{$this->getId()}')" disabled>💾 Sauvegarder</button>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    window.zdbSwitchTab = function(panelId, tabName, btn) {
        var wrap = document.getElementById('zdb-config-' + panelId);
        if (!wrap) return;

        wrap.querySelectorAll('.zdb-tab').forEach(function(t) { t.classList.remove('zdb-active'); });
        btn.classList.add('zdb-active');

        wrap.querySelectorAll('.zdb-tab-panel').forEach(function(p) { p.classList.remove('zdb-active'); });
        var panel = document.getElementById('zdb-tab-' + panelId + '-' + tabName);
        if (panel) panel.classList.add('zdb-active');
    };

    window.zdbToggleEdit = function(panelId) {
        var wrap = document.getElementById('zdb-config-' + panelId);
        if (!wrap) return;

        var isEdit = document.getElementById('zdb-edit-toggle-' + panelId).checked;
        var inputs = wrap.querySelectorAll('[data-zdb-field]');
        var saveBtn = document.getElementById('zdb-save-btn-' + panelId);

        inputs.forEach(function(input) {
            input.readOnly = !isEdit;
            input.style.background = isEdit ? '#0c0c12' : '';
            input.style.color = isEdit ? '#e2e8f0' : '';
        });

        if (saveBtn) saveBtn.disabled = !isEdit;
    };

    window.zdbCopyConfig = function(panelId) {
        var wrap = document.getElementById('zdb-config-' + panelId);
        if (!wrap) return;
        var textarea = wrap.querySelector('#zdb-raw-json-' + panelId);
        if (textarea) {
            textarea.select();
            document.execCommand('copy');
            alert('Configuration copied to the clipboard!');
        }
    };

    window.zdbDownloadConfig = function(panelId) {
        var wrap = document.getElementById('zdb-config-' + panelId);
        if (!wrap) return;
        var textarea = wrap.querySelector('#zdb-raw-json-' + panelId);
        if (textarea) {
            var blob = new Blob([textarea.value], { type: 'application/json' });
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = 'zara-config-' + new Date().toISOString().slice(0,10) + '.json';
            a.click();
            URL.revokeObjectURL(url);
        }
    };

    window.zdbSaveConfig = function(panelId) {
        var wrap = document.getElementById('zdb-config-' + panelId);
        if (!wrap) return;

        var textarea = wrap.querySelector('#zdb-raw-json-' + panelId);
        if (!textarea) return;

        try {
            var config = JSON.parse(textarea.value);
        } catch(e) {
            alert('Invalid JSON: ' + e.message);
            return;
        }

        var successAlert = document.getElementById('zdb-alert-success-' + panelId);
        var errorAlert = document.getElementById('zdb-alert-error-' + panelId);

        fetch('/_debug/config/save', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(config)
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                successAlert.style.display = 'block';
                errorAlert.style.display = 'none';
                setTimeout(function() { successAlert.style.display = 'none'; }, 3000);
            } else {
                throw new Error(data.message || 'Unknown error');
            }
        })
        .catch(function(err) {
            errorAlert.textContent = '❌ ' + err.message;
            errorAlert.style.display = 'block';
            successAlert.style.display = 'none';
            setTimeout(function() { errorAlert.style.display = 'none'; }, 5000);
        });
    };
})();
</script>
HTML;

        return $html;
    }
}

<?php
declare(strict_types=1);

namespace Zara\Container\Config;

use Zara\Container\Exceptions\ContainerException;

final class ConfigCacheManager
{
    public function loadFromCache(string $file, string $cacheFile): ?array
    {
        if (!file_exists($cacheFile)) {
            return null;
        }
        try {
            $cache = include $cacheFile;
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($cache) || !isset($cache['mtime']) || !isset($cache['data'])) {
            return null;
        }
        
        if (!isset($cache['hash']) || !hash_equals((string)$cache['hash'], hash_file('sha256', $file) ?: '')) {
            return null;
        }
        return $cache['data'];
    }

    public function saveToCache(string $sourceFile, string $cacheFile, array $config, int $mtime): void
    {
        $dir      = dirname($cacheFile);
        $exported = var_export([
            'mtime' => $mtime, 
            'hash' => hash_file('sha256', $sourceFile) ?: '',
            'data' => $config
            ],
            true
        );
        $content  = "<?php\n\n// Auto-generated. Do not edit.\n\nreturn " . $exported . ";\n";

        $umask = umask(0);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        umask($umask);

        $tmp = $dir . '/tmp_' . uniqid('cfg_', true) . '.tmp';

        if (file_put_contents($tmp, $content) === false) {
            throw new ContainerException("Unable to write the configuration cache: '$tmp'.");
        }

        if (!rename($tmp, $cacheFile)) {
            @unlink($tmp);
            throw new ContainerException("Unable to move the configuration cache to '$cacheFile'.");
        }
    }

}

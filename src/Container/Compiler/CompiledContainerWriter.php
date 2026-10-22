<?php
declare(strict_types=1);

namespace Zara\Container\Compiler;

use Zara\Container\Exceptions\ContainerException;

final class CompiledContainerWriter
{
    public function write(string $file, string $code, int $sourceMtime, string $fingerprint = ''): void
    {
        $dir = dirname($file);
        $umask = umask(0);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        umask($umask);

        $tmp = $dir . '/tmp_' . uniqid('compiled_', true) . '.tmp';

        if (file_put_contents($tmp, $code) === false) {
            throw new ContainerException("Unable to write the compiled container: '$tmp'.");
        }

        $metadata = ['source_mtime' => $sourceMtime, 'fingerprint' => $fingerprint, 'code_hash' => hash('sha256', $code)];
        $sidecar = $tmp . '.meta';
        if (file_put_contents($sidecar, serialize($metadata), LOCK_EX) === false) {
            @unlink($tmp);
            throw new ContainerException('Unable to write compiled container metadata.');
        }
        touch($tmp, $sourceMtime);

        if (!rename($tmp, $file)) {
            @unlink($tmp);
            @unlink($sidecar);
            throw new ContainerException("Unable to move the compiled container to '$file'.");
        }
        if (!@rename($sidecar, $file . '.meta')) {
            @unlink($file);
            @unlink($sidecar);
            throw new ContainerException('Unable to move compiled container metadata.');
        }
    }

    public function isFresh(string $file, int $sourceMtime): bool
    {
        return file_exists($file) && filemtime($file) >= $sourceMtime && is_file($file . '.meta');
    }

    public function matchesCodeHash(string $file): bool
    {
        $meta = @unserialize((string)@file_get_contents($file . '.meta'), ['allowed_classes' => false]);
        $hash = @hash_file('sha256', $file);
        return is_array($meta) && is_string($hash) && isset($meta['code_hash'])
            && hash_equals((string)$meta['code_hash'], $hash);
    }

    /**
     * Removes the compiled container if it exists and also removes orphaned
     * temporary files left by an interrupted write (a crash between
     * file_put_contents() and rename() in write()).
     *
     * @return bool true if a compiled file was removed, false if there was
     *              nothing to clean up.
     */
    public function clear(string $file): bool
    {
        $cleared = false;

        if (file_exists($file)) {
            if (!@unlink($file)) {
                throw new ContainerException("Unable to remove the compiled container: '$file'.");
            }
            $cleared = true;
        }
        if (file_exists($file . '.meta')) { @unlink($file . '.meta'); }

        foreach (glob(dirname($file) . '/tmp_compiled_*.tmp') ?: [] as $orphan) {
            @unlink($orphan);
        }
        foreach (glob(dirname($file) . '/tmp_compiled_*.tmp.meta') ?: [] as $orphan) {
            @unlink($orphan);
        }

        return $cleared;
    }
}

<?php
declare(strict_types=1);

namespace Zara\View\Cache\Internal;

use RuntimeException;
use Zara\View\Compiler\TemplateCompiler;

/**
 * Determines whether a compiled cache artifact is still fresh
 * relative to its source template.
 */
final class CacheValidator
{
    public function isValid(string $templatePath, string $cacheFile): bool
    {
        if (!file_exists($templatePath)) {
            throw new RuntimeException("Template file does not exist: {$templatePath}");
        }

        if (!file_exists($cacheFile)) {
            return false;
        }

        $sourceHash = hash_file('sha256', $templatePath);
        $header = file_get_contents($cacheFile, false, null, 0, 128);
        if ($sourceHash === false || $header === false) {
            throw new RuntimeException("Unable to verify template cache: {$cacheFile}");
        }

        if (!preg_match('~\\A<\\?php /\\* ZARA_SOURCE_SHA256:([a-f0-9]{64}) ZARA_COMPILER_VERSION:([0-9]+) \\*/ \\?>~', $header, $matches)) {
            // Caches written before source fingerprints are treated as stale.
            return false;
        }

        return hash_equals($sourceHash, $matches[1])
            && hash_equals(TemplateCompiler::CACHE_VERSION, $matches[2]);
    }
}

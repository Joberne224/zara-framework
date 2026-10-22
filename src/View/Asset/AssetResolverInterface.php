<?php
declare(strict_types=1);

namespace Zara\View\Asset;

interface AssetResolverInterface
{
    /**
     * Verifies that $path points to a real file within the public directory
     * and returns its canonical path relative to public/. This method only
     * resolves filesystem paths; it does not build URLs.
     *
     * @throws \RuntimeException If the file does not exist or the resolved
     *                            path escapes the public directory.
     */
    public function resolve(string $path): string;
}

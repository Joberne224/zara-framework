<?php
// file: Zara/Core/Http/Interfaces/FileManagerInterface.php
declare(strict_types=1);

namespace Zara\Core\Files\Interfaces;

use Zara\Core\Files\Interfaces\FileCollectionInterface;

/**
 * Définit le contrat pour un normaliseur de fichiers.
 */
interface FileNormalizerInterface
{
    /**
     * Normalise la superglobale $_FILES en une collection de fichiers.
     *
     * @param array $files L'array $_FILES.
     * @return FileCollectionInterface
     */
    public function normalize(array $files): FileCollectionInterface;
}

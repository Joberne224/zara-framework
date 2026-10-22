<?php

declare(strict_types=1);

namespace Zara\Core\Files;

use InvalidArgumentException;
use Zara\Core\Files\Interfaces\FileCollectionInterface;
use Zara\Core\Files\Interfaces\FileNormalizerInterface;

class FileNormalizer implements FileNormalizerInterface
{
    public function normalize(array $files): FileCollectionInterface
    {
        $normalizedStructure = [];

        foreach ($files as $key => $fileData) {
            if (!is_array($fileData) || !array_key_exists('name', $fileData)) {
                throw new InvalidArgumentException(sprintf("Malformed upload data for field '%s'.", (string) $key));
            }

            if (is_array($fileData['name'])) {
                $subStructure = self::normalizeComplexFiles($fileData);
                if ($subStructure !== []) {
                    $normalizedStructure[$key] = new FileCollection($subStructure);
                }

                continue;
            }

            $file = self::normalizeFile($fileData);
            if ($file !== null) {
                $normalizedStructure[$key] = $file;
            }
        }

        return new FileCollection($normalizedStructure);
    }

    /**
     * @return array<string|int, File|FileCollection>
     */
    private static function normalizeComplexFiles(array $fileData): array
    {
        foreach (['type', 'tmp_name', 'error', 'size'] as $field) {
            if (!isset($fileData[$field]) || !is_array($fileData[$field])) {
                throw new InvalidArgumentException(sprintf("Malformed nested upload data: '%s' must be an array.", $field));
            }
        }

        $structure = [];
        $names = $fileData['name'];

        foreach ($names as $key => $name) {
            $entry = ['name' => $name];

            foreach (['type', 'tmp_name', 'error', 'size'] as $field) {
                if (!array_key_exists($key, $fileData[$field])) {
                    throw new InvalidArgumentException(
                        sprintf("Malformed nested upload data: missing '%s' entry for key '%s'.", $field, (string) $key)
                    );
                }

                $entry[$field] = $fileData[$field][$key];
            }

            if (is_array($name)) {
                $nested = self::normalizeComplexFiles($entry);
                if ($nested !== []) {
                    $structure[$key] = new FileCollection($nested);
                }

                continue;
            }

            $file = self::normalizeFile($entry);
            if ($file !== null) {
                $structure[$key] = $file;
            }
        }

        return $structure;
    }

    /**
     * @return File|null Null when no file was uploaded for this entry.
     */
    private static function normalizeFile(array $fileData): ?File
    {
        $name = $fileData['name'] ?? '';
        $type = $fileData['type'] ?? '';
        $tmpName = $fileData['tmp_name'] ?? '';
        $error = $fileData['error'] ?? UPLOAD_ERR_NO_FILE;
        $size = $fileData['size'] ?? 0;

        if (!is_string($name) || !is_string($type) || !is_string($tmpName)) {
            throw new InvalidArgumentException('Upload name, type, and temporary path must be strings.');
        }

        if (!is_int($error) || !is_int($size)) {
            throw new InvalidArgumentException('Upload error code and size must be integers.');
        }

        if ($error === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        return new File($name, $type, $tmpName, $error, $size);
    }
}

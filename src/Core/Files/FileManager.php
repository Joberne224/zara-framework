<?php
declare(strict_types=1);

namespace Zara\Core\Files;

use Zara\Core\Files\Interfaces\FileInterface;
use Zara\Core\Files\Interfaces\FileManagerInterface;
use Zara\Core\Files\FileValidationResult;
use Zara\Core\Storage\DiskInterface;
use Zara\Core\Storage\Exceptions\StorageException;

class FileManager implements FileManagerInterface
{
    /**
     * Maps an allowed extension to the real MIME type(s) its content must
     * match. This is checked against the file's actual bytes (magic
     * numbers), never against $_FILES['type'] — that header is set by the
     * client and can be forged freely, so trusting it would defeat the
     * point of validating at all.
     */
    private const ALLOWED_MIME_TYPES = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'pdf'  => ['application/pdf'],
    ];

    private array $allowedExtensions;
    private array $mimeTypes;
    private int $maxSize;

    /**
     * @param list<string> $allowedExtensions
     * @param array<string, list<string>>|null $mimeTypes
     */
    public function __construct(
        private readonly DiskInterface $disk,
        array $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf'],
        int $maxSize = 5242880,
        ?array $mimeTypes = null,
    ) {
        if ($maxSize < 0) {
            throw new \InvalidArgumentException('Maximum file size cannot be negative.');
        }

        foreach ($allowedExtensions as $extension) {
            if (!is_string($extension) || trim($extension) === '') {
                throw new \InvalidArgumentException('Allowed file extensions must be non-empty strings.');
            }
        }

        $normalizedExtensions = array_map(
            static fn (string $extension): string => strtolower(ltrim(trim($extension), '.')),
            $allowedExtensions
        );
        if (in_array('', $normalizedExtensions, true)) {
            throw new \InvalidArgumentException('Allowed file extensions must contain at least one character.');
        }

        $this->allowedExtensions = array_values(array_unique($normalizedExtensions));
        $this->maxSize = $maxSize;
        $mimeTypes ??= self::ALLOWED_MIME_TYPES;
        $this->mimeTypes = [];

        foreach ($mimeTypes as $extension => $types) {
            if (!is_string($extension) || trim($extension) === '' || !is_array($types) || $types === []) {
                throw new \InvalidArgumentException('MIME types must map extensions to non-empty lists of MIME type strings.');
            }

            $normalizedExtension = strtolower(ltrim(trim($extension), '.'));
            if ($normalizedExtension === '') {
                throw new \InvalidArgumentException('MIME type extension keys must contain at least one character.');
            }

            foreach ($types as $type) {
                if (!is_string($type) || trim($type) === '') {
                    throw new \InvalidArgumentException('MIME type values must be non-empty strings.');
                }
            }

            $this->mimeTypes[$normalizedExtension] = array_values($types);
        }

        foreach ($this->allowedExtensions as $extension) {
            if (!isset($this->mimeTypes[$extension])) {
                throw new \InvalidArgumentException(
                    sprintf("No MIME types are configured for the allowed extension '%s'.", $extension)
                );
            }
        }
    }

    public function store(FileInterface $file, string $destination, ?string $filename = null): bool
    {
        $validation = $this->validateFile($file);

        if (!$validation->isValid()) {
            return false;
        }

        // Without this, we lose the guarantee move_uploaded_file() used to
        // give us for free: that tmpName really came from an HTTP upload,
        // not an arbitrary path.
        if (!is_uploaded_file($file->getTmpName())) {
            return false;
        }

        $ext      = strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION));
        $basename = $filename ?? bin2hex(random_bytes(16));

        // Strip any directory separators a caller-supplied $filename might
        // carry — File::__construct already guards getName(), but $filename
        // here is a separate input we don't control the same way.
        $basename = basename($basename);

        $relativePath = rtrim($destination, '/') . '/' . $basename . '.' . $ext;

        $contents = file_get_contents($file->getTmpName());
        if ($contents === false) {
            return false;
        }

        try {
            // PathResolver (via disk) neutralizes any residual traversal in
            // $relativePath, and the write is atomic (tmp + rename).
            $this->disk->write($relativePath, $contents);
        } catch (StorageException) {
            return false;
        }

        return true;
    }

    public function validate(FileInterface $file): bool
    {
        return $this->validateFile($file)->isValid();
    }

    private function validateFile(FileInterface $file): FileValidationResult
    {
        $errors = [];

        if ($file->getError() !== UPLOAD_ERR_OK) {
            $errors[] = "Upload error (code {$file->getError()}).";
        }

        if ($file->getSize() > $this->maxSize) {
            $errors[] = "File exceeds the maximum allowed size (" . ($this->maxSize / 1024 / 1024) . " MB).";
        }

        $ext = strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION));
        if (!in_array($ext, $this->allowedExtensions, true)) {
            $errors[] = "Extension not allowed: .$ext";
        }

        // Inspect real file contents instead of trusting the client MIME type.
        if ($file->getError() === UPLOAD_ERR_OK) {
            if (!is_file($file->getTmpName()) || !is_readable($file->getTmpName())) {
                $errors[] = 'Temporary file is missing or unreadable.';
            } else {
                $realMime = $this->detectRealMimeType($file->getTmpName());
                $expectedMimes = $this->mimeTypes[$ext] ?? [];

                if ($realMime === null || !in_array($realMime, $expectedMimes, true)) {
                    $errors[] = "File content does not match its extension" .
                        ($realMime !== null ? " (detected: {$realMime})." : '.');
                }
            }
        }

        return new FileValidationResult(empty($errors), $errors);
    }

    private function detectRealMimeType(string $tmpPath): ?string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);

        $mime = $finfo->file($tmpPath);

        return $mime !== false ? $mime : null;
    }
    
    public function delete(string $path): bool
    {
        try {
            $this->disk->delete($path);
            return true;
        } catch (StorageException) {
            return false;
        }
    }
}
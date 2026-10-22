<?php
// file: Zara/Core/Http/Interfaces/FileManagerInterface.php
declare(strict_types=1);

namespace Zara\Core\Files\Interfaces;

use Zara\Core\Files\Interfaces\FileInterface;

/**
 * Defines the contract for a file management service.
 *
 * This interface outlines the core functionalities for handling uploaded files,
 * such as storing, validating, and deleting them.
 */
interface FileManagerInterface
{
    /**
     * Stores an uploaded file in a specified location.
     *
     * @param FileInterface $file The file to store.
     * @param string $destination The destination path.
     * @return bool Returns true on success, false otherwise.
     */
    public function store(FileInterface $file, string $destination): bool;

    /**
     * Validates an uploaded file based on predefined rules.
     *
     * @param FileInterface $file The file to validate.
     * @return bool Returns true if the file is valid, false otherwise.
     */
    public function validate(FileInterface $file): bool;

    /**
     * Deletes a file from the filesystem.
     *
     * @param string $path The path to the file.
     * @return bool Returns true on success, false otherwise.
     */
    public function delete(string $path): bool;
}

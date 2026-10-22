<?php
declare(strict_types=1);

namespace Zara\Core\Files;

use ArrayIterator;
use RecursiveArrayIterator;
use RecursiveIteratorIterator;
use Zara\Core\Files\Interfaces\FileInterface;
use Zara\Core\Files\Interfaces\FileCollectionInterface;

class FileCollection implements FileCollectionInterface
{
    /** @var array<string|int, FileInterface|FileCollectionInterface> */
    private array $files = [];

    public function __construct(array $files = [])
    {
        foreach ($files as $key => $file) {
            if (!$file instanceof FileInterface && !$file instanceof FileCollectionInterface) {
                throw new \InvalidArgumentException('FileCollection only accepts FileInterface or FileCollectionInterface instances.');
            }
        }
        $this->files = $files;
    }

    public function get(string $key): FileInterface|FileCollectionInterface|null
    {
        // Gère les clés de type 'avatar' ou 'documents[0]' ou 'user[documents][cv]'
        $parts = preg_split('/[[\]]+/', $key, -1, PREG_SPLIT_NO_EMPTY);
        $current = $this;

        foreach ($parts as $part) {
            if ($current instanceof FileCollectionInterface) {
                $filesArray = $current->all();
                if (array_key_exists($part, $filesArray)) {
                    $current = $filesArray[$part];
                } else {
                    return null;
                }
            } else {
                return null;
            }
        }
        return $current;
    }

    public function all(): array
    {
        return $this->files;
    }

    public function addFile(FileInterface $file): self
    {
        $files = $this->files;
        $files[] = $file;

        return new self($files);
    }

    public function merge(FileCollectionInterface $other): self
    {
        // Remplacement de array_merge par l'opérateur + pour préserver l'indexation associative des clés d'origine
        return new self($this->files + $other->all());
    }

    public function count(): int
    {
        // Compte récursif de tous les fichiers physiques contenus
        $count = 0;
        foreach ($this->files as $item) {
            if ($item instanceof FileInterface) {
                $count++;
            } elseif ($item instanceof FileCollectionInterface) {
                $count += $item->count();
            }
        }
        return $count;
    }

    public function getIterator(): ArrayIterator
    {
        // Pour conserver la rétrocompatibilité, on aplatit l'itérateur si nécessaire, 
        // ou on fournit la liste directe du premier niveau.
        return new ArrayIterator($this->files);
    }
}
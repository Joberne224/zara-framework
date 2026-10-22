<?php
declare(strict_types=1);

namespace Zara\Core\Files;

use Zara\Core\Files\Interfaces\FileInterface;

class File implements FileInterface
{   
    private readonly string $name;
    private readonly string $type;
    private readonly string $tmpName; // Fix: Changé en readonly  
    private readonly int $error;
    private readonly int $size;
    
    public function __construct(
        string $name,
        string $type,
        string $tmpName,
        int $error,
        int $size
    )
    {  
        if (empty($name) || preg_match('/[\/\\\\]/', $name)) {
            throw new \InvalidArgumentException('File name is invalid or contains forbidden characters.');
        }

        $this->name = $name;
        $this->type = $type;
        $this->tmpName = $tmpName;
        $this->error = $error;
        $this->size = $size;
    }   

    public function getName(): string
    {
        return $this->name;
    }   

    public function getType(): string
    {
        return $this->type;
    }

    public function getTmpName(): string
    {
        return $this->tmpName;
    }

    public function getError(): int
    {
        return $this->error;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function withName(string $name): self
    {
        return new self(
            $name,
            $this->type,
            $this->tmpName,
            $this->error, 
            $this->size
        );
    }
}
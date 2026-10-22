<?php
declare(strict_types=1);

namespace Zara\Core\Files\Interfaces;

interface FileInterface
{
    public function getName(): string;
       
    public function getType(): string;
    
    public function getTmpName(): string;
   
    public function getSize(): int;
    
    public function getError(): int;

    public function withName(string $name): self;

}
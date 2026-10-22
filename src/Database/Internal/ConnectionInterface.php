<?php
declare(strict_types=1);

namespace Zara\Database\Internal;

interface ConnectionInterface
{
    public function connect(): void;

    public function disconnect(): void;

    public function isConnected(): bool;
}

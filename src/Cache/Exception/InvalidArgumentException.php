<?php
// Zara\Cache\Exception\InvalidArgumentException.php
declare(strict_types=1);

namespace Zara\Cache\Exception;

final class InvalidArgumentException extends CacheException implements \Psr\SimpleCache\InvalidArgumentException
{
}
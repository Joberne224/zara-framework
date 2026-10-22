<?php
// Zara\Cache\Exception\CacheException.php
declare(strict_types=1);

namespace Zara\Cache\Exception;

class CacheException extends \RuntimeException implements \Psr\SimpleCache\CacheException
{
}
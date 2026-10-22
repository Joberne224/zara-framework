<?php
// Zara\Cache\CacheDriver.php
declare(strict_types=1);

namespace Zara\Cache;

enum CacheDriver
{
    case Redis;
    case Fallback;
    case Filesystem;
    case Sqlite;
    case Array;
    case Null;
}

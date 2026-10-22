<?php
declare(strict_types=1);

namespace Zara\Database\Instrumentation;

final class QueryObserverRegistry
{
    private static ?QueryObserverInterface $observer = null;

    public static function set(?QueryObserverInterface $observer): void
    {
        self::$observer = $observer;
    }

    public static function current(): ?QueryObserverInterface
    {
        return self::$observer;
    }
}

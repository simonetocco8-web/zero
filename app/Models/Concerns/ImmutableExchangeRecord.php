<?php

namespace App\Models\Concerns;

trait ImmutableExchangeRecord
{
    public static function bootImmutableExchangeRecord(): void
    {
        static::updating(fn () => throw new \LogicException('Exchange versions and history are immutable.'));
        static::deleting(fn () => throw new \LogicException('Exchange versions and history must be retained.'));
    }
}

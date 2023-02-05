<?php declare(strict_types=1);

namespace Amp\Http\Server\StaticContent\Internal;

/**
 * Used for range array in {@see ByteRangeRequest}.
 */
class ByteRange
{
    public function __construct(
        public readonly int $start,
        public readonly int $end,
    ) {
    }
}

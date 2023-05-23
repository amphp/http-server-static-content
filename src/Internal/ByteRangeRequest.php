<?php declare(strict_types=1);

namespace Amp\Http\Server\StaticContent\Internal;

use Amp\Http\Server\StaticContent\DocumentRoot;

/**
 * Used in {@see DocumentRoot}.
 *
 * @internal
 */
final class ByteRangeRequest
{
    /**
     * @param non-empty-list<ByteRange> $ranges
     */
    public function __construct(
        public readonly string $boundary,
        public readonly array $ranges,
        public readonly string $contentType,
    ) {
    }
}

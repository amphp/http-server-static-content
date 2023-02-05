<?php declare(strict_types=1);

namespace Amp\Http\Server\StaticContent\Internal;

/**
 * Used in Amp\Http\Server\StaticContent\DocumentRoot.
 */
final class ByteRange
{
    /**
     * @param non-empty-list<array{int, int}> $ranges
     */
    public function __construct(
        public readonly string $boundary,
        public readonly array $ranges,
        public readonly string $contentType,
    ) {
    }
}

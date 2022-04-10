<?php

namespace Amp\Http\Server\StaticContent\Internal;

/**
 * Used in Amp\Http\Server\StaticContent\DocumentRoot.
 */
final class ByteRange
{
    public ?string $contentType = null;

    public function __construct(
        public readonly string $boundary,
        public readonly array $ranges,
    ) {
    }
}

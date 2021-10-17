<?php

namespace Amp\Http\Server\StaticContent\Internal;

/**
 * Used in Amp\Http\Server\StaticContent\DocumentRoot.
 */
final class ByteRange
{
    public array $ranges;
    public string $boundary;
    public string $contentType;
}

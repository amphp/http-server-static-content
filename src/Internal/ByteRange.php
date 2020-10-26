<?php

namespace Amp\Http\Server\StaticContent\Internal;

use Amp\Struct;

/**
 * Used in Amp\Http\Server\StaticContent\DocumentRoot.
 */
final class ByteRange
{
    use Struct;

    public array $ranges;
    public string $boundary;
    public string $contentType;
}

<?php

namespace Amp\Http\Server\File\Internal;

use Amp\Struct;

/**
 * Used in Amp\Http\Server\File\Root.
 */
final class ByteRange {
    use Struct;

    public $ranges;
    public $boundary;
    public $contentType;
}

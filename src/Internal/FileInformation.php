<?php

namespace Amp\Http\Server\StaticContent\Internal;

/**
 * Used in Amp\Http\Server\StaticContent\DocumentRoot.
 */
final class FileInformation
{
    public bool $exists = false;

    public string $path;

    public ?int $size = null;

    public ?int $mtime = null;

    public ?int $inode = null;

    public ?string $buffer = null;

    public ?string $etag = null;
}

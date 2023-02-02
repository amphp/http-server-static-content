<?php

namespace Amp\Http\Server\StaticContent\Internal;

/**
 * Used in Amp\Http\Server\StaticContent\DocumentRoot.
 */
final class FileInformation
{
    public bool $exists = false;

    public ?int $size = null;

    public ?int $mtime = null;

    public ?int $inode = null;

    public ?string $buffer = null;

    public ?string $etag = null;

    /**
     * @param \Closure(string $path, ?string $buffer): void $onDispose
     */
    public function __construct(
        public string $path,
        private readonly \Closure $onDispose,
    ) {
    }

    public function __destruct()
    {
        ($this->onDispose)($this->path, $this->buffer);
    }
}

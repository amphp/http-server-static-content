<?php declare(strict_types=1);

namespace Amp\Http\Server\StaticContent\Internal;

use Amp\Http\Server\StaticContent\DocumentRoot;

/**
 * Used in {@see DocumentRoot}.
 *
 * @internal
 */
final class FileInformation
{
    public static function fromNonExistentFile(string $path): self
    {
        return new self($path, false, 0, 0, 0, '');
    }

    public static function fromUnbufferedFile(string $path, array $stat, bool $useEtagINode): self
    {
        return self::create($path, $stat, $useEtagINode);
    }

    public static function fromBufferedFile(
        string $path,
        array $stat,
        bool $useEtagINode,
        string $buffer,
    ): self {
        return self::create($path, $stat, $useEtagINode, $buffer);
    }

    private static function create(
        string $path,
        array $stat,
        bool $useEtagINode,
        ?string $buffer = null,
    ): self {
        $size = $buffer === null ? (int) $stat["size"] : \strlen($buffer);
        $mtime = $stat["mtime"] ?? 0;
        $inode = $stat["ino"] ?? 0;
        $etag = \md5($path . $mtime . $size . ($useEtagINode ? $inode : ''));

        return new self($path, true, $size, $mtime, $inode, $etag, $buffer);
    }

    private function __construct(
        public readonly string $path,
        public readonly bool $exists,
        public readonly int $size,
        public readonly int $mtime,
        public readonly int $inode,
        public readonly string $etag,
        public readonly ?string $buffer = null,
    ) {
    }
}

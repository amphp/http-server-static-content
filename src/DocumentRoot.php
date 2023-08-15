<?php declare(strict_types=1);

namespace Amp\Http\Server\StaticContent;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\ReadableIterableStream;
use Amp\ByteStream\ReadableStream;
use Amp\Cache\LocalCache;
use Amp\File\File;
use Amp\File\Filesystem;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Http\HttpStatus;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\StaticContent\Internal\Precondition;
use Amp\Pipeline\Pipeline;
use function Amp\File\filesystem;
use function Amp\File\read;
use function Amp\Http\formatDateHeader;

final class DocumentRoot implements RequestHandler
{
    use ForbidCloning;
    use ForbidSerialization;

    /** @var string Default mime file path. */
    public const DEFAULT_MIME_TYPE_FILE = __DIR__ . "/../resources/mime";

    private const READ_CHUNK_SIZE = 8192;

    private bool $running = false;

    private ?RequestHandler $fallback = null;

    private readonly string $root;
    private readonly bool $debug;
    private readonly Filesystem $filesystem;
    private readonly string $multipartBoundary;

    /** @var LocalCache<Internal\FileInformation> */
    private readonly LocalCache $cache;

    private readonly \WeakMap $buffered;

    private array $mimeTypes = [];
    private array $mimeFileTypes = [];
    private array $indexes = ["index.html", "index.htm"];
    private bool $useEtagInode = true;
    private int $expiresPeriod = 86400 * 7;
    private string $defaultMimeType = "text/plain";
    private string $defaultCharset = "utf-8";
    private bool $useAggressiveCacheHeaders = false;
    private float $aggressiveCacheMultiplier = 0.9;
    private int $cacheEntryTtl = 10;
    private int $cacheEntryLimit = 2048;
    private int $bufferedFileLimit = 50;
    private int $bufferedFileSizeLimit = 524288;

    /**
     * @param string $root Document root
     * @param Filesystem|null $filesystem Optional filesystem driver
     */
    public function __construct(
        HttpServer $httpServer,
        private readonly ErrorHandler $errorHandler,
        string $root,
        ?Filesystem $filesystem = null
    ) {
        $root = \str_replace("\\", "/", $root);
        if (!(\is_readable($root) && \is_dir($root))) {
            throw new \Error(
                "Document root requires a readable directory"
            );
        }

        if (\strncmp($root, "phar://", 7) !== 0) {
            $root = \realpath($root);
        }

        $this->root = \rtrim($root, "/");
        $this->filesystem = $filesystem ?? filesystem();
        $this->multipartBoundary = \strtr(\base64_encode(\random_bytes(16)), '+/', '-_');
        $this->debug = \ini_get('zend.assertions') === '1';

        $this->cache = new LocalCache();
        $this->buffered = new \WeakMap();

        $httpServer->onStart($this->onStart(...));
        $httpServer->onStop($this->onStop(...));
    }

    /**
     * Specifies an instance of RequestHandler that is used if no file exists for the requested path.
     * If no fallback is given, a 404 response is returned from respond() when the file does not exist.
     *
     * @throws \Error If the server has started.
     */
    public function setFallback(RequestHandler $requestHandler): void
    {
        if ($this->running) {
            throw new \Error("Cannot add fallback request handler after the server has started");
        }

        $this->fallback = $requestHandler;
    }

    /**
     * Respond to HTTP requests for filesystem resources.
     *
     * @param Request $request Request to handle.
     */
    public function handleRequest(Request $request): Response
    {
        $path = removeDotPathSegments($request->getUri()->getPath());

        return ($fileInfo = $this->fetchCachedStat($path, $request))
            ? $this->respondFromFileInfo($fileInfo, $request)
            : $this->respondWithLookup($this->root . $path, $path, $request);
    }

    private function fetchCachedStat(string $reqPath, Request $request): ?Internal\FileInformation
    {
        // We specifically allow users to bypass cached representations by using their browser's "force refresh"
        // functionality. This lets us avoid the annoyance of stale file representations being served for a few seconds
        // after changes have been written to disk.
        if ($this->debug) {
            return null;
        }

        foreach ($request->getHeaderArray("Cache-Control") as $value) {
            if (\strcasecmp($value, "no-cache") === 0) {
                return null;
            }
        }

        foreach ($request->getHeaderArray("Pragma") as $value) {
            if (\strcasecmp($value, "no-cache") === 0) {
                return null;
            }
        }

        return $this->cache->get($reqPath);
    }

    private function shouldBufferContent(array $stat): bool
    {
        $size = (int) ($stat["size"] ?? 0);

        if (!$size || $size > $this->bufferedFileSizeLimit) {
            return false;
        }

        if ($this->buffered->count() >= $this->bufferedFileLimit) {
            return false;
        }

        if ($this->cache->count() >= $this->cacheEntryLimit) {
            return false;
        }

        return true;
    }

    private function respondWithLookup(string $realPath, string $reqPath, Request $request): Response
    {
        // We don't catch any potential exceptions from this yield because they represent
        // a legitimate error from some sort of disk failure. Just let them bubble up to
        // the server where they'll turn into a 500 response.
        $fileInfo = $this->lookup($realPath);

        // Specifically use the request path to reference this file in the
        // cache because the file entry path may differ if it's reflecting
        // a directory index file.
        if ($this->cache->count() < $this->cacheEntryLimit) {
            $this->cache->set($reqPath, $fileInfo, $this->cacheEntryTtl);
        }

        return $this->respondFromFileInfo($fileInfo, $request);
    }

    private function lookup(string $path): Internal\FileInformation
    {
        if (!($stat = $this->filesystem->getStatus($path))) {
            return Internal\FileInformation::fromNonExistentFile($path);
        }

        if ($this->filesystem->isDirectory($path)) {
            if (!($indexPathArr = $this->coalesceIndexPath($path))) {
                return Internal\FileInformation::fromNonExistentFile($path);
            }

            [$path, $stat] = $indexPathArr;
        }

        if ($this->shouldBufferContent($stat)) {
            $fileInfo = Internal\FileInformation::fromBufferedFile(
                $path,
                $stat,
                $this->useEtagInode,
                $this->filesystem->read($path),
            );

            /** @psalm-suppress InaccessibleProperty $this->buffered is a WeakMap */
            $this->buffered[$fileInfo] = $fileInfo->size;

            return $fileInfo;
        }

        return Internal\FileInformation::fromUnbufferedFile($path, $stat, $this->useEtagInode);
    }

    private function coalesceIndexPath(string $dirPath): ?array
    {
        $dirPath = \rtrim($dirPath, "/") . "/";
        foreach ($this->indexes as $indexFile) {
            $coalescedPath = $dirPath . $indexFile;
            if ($this->filesystem->isFile($coalescedPath)) {
                $stat = $this->filesystem->getStatus($coalescedPath);
                return [$coalescedPath, $stat];
            }
        }

        return null;
    }

    private function respondFromFileInfo(Internal\FileInformation $fileInfo, Request $request): Response
    {
        if (!$fileInfo->exists) {
            if ($this->fallback !== null) {
                return $this->fallback->handleRequest($request);
            }

            return $this->errorHandler->handleError(HttpStatus::NOT_FOUND, request: $request);
        }

        switch ($request->getMethod()) {
            case "GET":
            case "HEAD":
                break;

            case "OPTIONS":
                return new Response(HttpStatus::NO_CONTENT, [
                    "Allow" => "GET, HEAD, OPTIONS",
                    "Accept-Ranges" => "bytes",
                ]);

            default:
                $response = $this->errorHandler->handleError(HttpStatus::METHOD_NOT_ALLOWED, request: $request);
                $response->setHeader("Allow", "GET, HEAD, OPTIONS");
                return $response;
        }

        $precondition = $this->checkPreconditions($request, $fileInfo->mtime, $fileInfo->etag);

        switch ($precondition) {
            case Precondition::Ok:
            case Precondition::IfRangeOk:
                break;

            case Precondition::NotModified:
                $lastModifiedHttpDate = formatDateHeader($fileInfo->mtime);
                $response = new Response(HttpStatus::NOT_MODIFIED, ["Last-Modified" => $lastModifiedHttpDate]);
                if ($fileInfo->etag) {
                    $response->setHeader("Etag", $fileInfo->etag);
                }
                return $response;

            case Precondition::Failed:
                return $this->errorHandler->handleError(HttpStatus::PRECONDITION_FAILED, request: $request);

            case Precondition::IfRangeFailed:
                return $this->doNonRangeResponse($fileInfo);
        }

        if (!$rangeHeader = $request->getHeader("Range")) {
            return $this->doNonRangeResponse($fileInfo);
        }

        if ($range = $this->normalizeByteRanges($fileInfo, $rangeHeader)) {
            return $this->doRangeResponse($range, $fileInfo);
        }

        // If we're still here this is the only remaining response we can send
        $response = $this->errorHandler->handleError(HttpStatus::RANGE_NOT_SATISFIABLE, request: $request);
        $response->setHeader("Content-Range", "*/{$fileInfo->size}");
        return $response;
    }

    private function checkPreconditions(Request $request, int $mtime, string $etag): Precondition
    {
        $ifMatch = $request->getHeader("If-Match");
        if ($ifMatch && \stripos($ifMatch, $etag) === false) {
            return Precondition::Failed;
        }

        $ifNoneMatch = $request->getHeader("If-None-Match");
        if ($ifNoneMatch && \stripos($ifNoneMatch, $etag) !== false) {
            return Precondition::NotModified;
        }

        $ifModifiedSince = $request->getHeader("If-Modified-Since");
        $ifModifiedSince = $ifModifiedSince ? \strtotime($ifModifiedSince) : 0;
        if ($ifModifiedSince && $mtime > $ifModifiedSince) {
            return Precondition::NotModified;
        }

        $ifUnmodifiedSince = $request->getHeader("If-Unmodified-Since");
        $ifUnmodifiedSince = $ifUnmodifiedSince ? \strtotime($ifUnmodifiedSince) : 0;
        if ($ifUnmodifiedSince && $mtime > $ifUnmodifiedSince) {
            return Precondition::Failed;
        }

        $ifRange = $request->getHeader("If-Range");
        if ($ifRange === null || !$request->getHeader("Range")) {
            return Precondition::Ok;
        }

        /**
         * This is a really stupid feature of HTTP but ...
         * If-Range headers may be either an HTTP timestamp or an Etag:.
         *
         *     If-Range = "If-Range" ":" ( entity-tag | HTTP-date )
         *
         * @link https://tools.ietf.org/html/rfc7233#section-3.2
         */
        if ($httpDate = \strtotime($ifRange)) {
            return ($httpDate > $mtime) ? Precondition::IfRangeOk : Precondition::IfRangeFailed;
        }

        // If the If-Range header was not an HTTP date we assume it's an Etag
        return ($etag === $ifRange) ? Precondition::IfRangeOk : Precondition::IfRangeFailed;
    }

    private function doNonRangeResponse(Internal\FileInformation $fileInfo): Response
    {
        $headers = $this->makeCommonHeaders($fileInfo);
        $headers["Content-Type"] = $this->selectMimeTypeFromPath($fileInfo->path);

        if ($fileInfo->buffer !== null) {
            $headers["Content-Length"] = (string) $fileInfo->size;

            return new Response(HttpStatus::OK, $headers, new ReadableBuffer($fileInfo->buffer));
        }

        // Don't use cached size if we don't have buffered file contents,
        // otherwise we get truncated files during development.
        $headers["Content-Length"] = (string) $this->filesystem->getSize($fileInfo->path);

        $handle = $this->filesystem->openFile($fileInfo->path, "r");

        $response = new Response(HttpStatus::OK, $headers, $handle);
        $response->onDispose($handle->close(...));
        return $response;
    }

    private function makeCommonHeaders(Internal\FileInformation $fileInfo): array
    {
        $headers = [
            "Accept-Ranges" => "bytes",
            "Cache-Control" => "public",
            "Etag" => $fileInfo->etag,
            "Last-Modified" => formatDateHeader($fileInfo->mtime),
        ];

        $canCache = ($this->expiresPeriod > 0);
        if ($canCache && $this->useAggressiveCacheHeaders) {
            $postCheck = (int) ($this->expiresPeriod * $this->aggressiveCacheMultiplier);
            $preCheck = $this->expiresPeriod - $postCheck;
            $value = ", post-check={$postCheck}, pre-check={$preCheck}, max-age={$this->expiresPeriod}";
            $headers["Cache-Control"] .= $value;
        } elseif ($canCache) {
            $expiry = \time() + $this->expiresPeriod;
            $headers["Cache-Control"] .= ", max-age={$this->expiresPeriod}";
            $headers["Expires"] = formatDateHeader($expiry);
        } else {
            $headers["Expires"] = "0";
        }

        return $headers;
    }

    private function selectMimeTypeFromPath(string $path): string
    {
        $ext = \pathinfo($path, PATHINFO_EXTENSION);
        if (!$ext) {
            $mimeType = $this->defaultMimeType;
        } else {
            $ext = \strtolower($ext);
            $mimeType = $this->mimeTypes[$ext] ?? $this->mimeFileTypes[$ext] ?? $this->defaultMimeType;
        }

        if (\stripos($mimeType, "text/") === 0 && \stripos($mimeType, "charset=") === false) {
            $mimeType .= "; charset={$this->defaultCharset}";
        }

        return $mimeType;
    }

    /**
     * @link https://tools.ietf.org/html/rfc7233#section-2.1
     *
     * @param string $rawRanges Ranges as provided by the client.
     */
    private function normalizeByteRanges(
        Internal\FileInformation $fileInfo,
        string $rawRanges
    ): ?Internal\ByteRangeRequest {
        $rawRanges = \str_ireplace([' ', 'bytes='], '', $rawRanges);

        $ranges = [];
        $size = $fileInfo->size;

        foreach (\explode(',', $rawRanges) as $range) {
            // If a range is missing the dash separator it's malformed; pull out here.
            if (!\str_contains($range, '-')) {
                return null;
            }

            /** @psalm-suppress PossiblyUndefinedArrayOffset String contains a dash, checked above */
            [$startPos, $endPos] = \explode('-', \rtrim($range), 2);

            if ($startPos === '' && $endPos !== '') {
                // The -1 is necessary and not a hack because byte ranges are inclusive and start
                // at 0. DO NOT REMOVE THE -1.
                $startPos = $size - (int) $endPos - 1;
                $endPos = $size - 1;
            } elseif ($endPos === '' && $startPos !== '') {
                $startPos = (int) $startPos;
                // The -1 is necessary and not a hack because byte ranges are inclusive and start
                // at 0. DO NOT REMOVE THE -1.
                $endPos = $size - 1;
            } elseif ($startPos === '' && $endPos === '') {
                return null;
            } else {
                $startPos = (int) $startPos;
                $endPos = (int) $endPos;
            }

            // If the requested range(s) can't be satisfied we're finished
            if ($startPos >= $size || $endPos < $startPos || $endPos < 0) {
                return null;
            }

            $ranges[] = new Internal\ByteRange($startPos, $endPos);
        }

        return new Internal\ByteRangeRequest(
            $this->multipartBoundary,
            $ranges,
            $this->selectMimeTypeFromPath($fileInfo->path),
        );
    }

    private function doRangeResponse(Internal\ByteRangeRequest $request, Internal\FileInformation $fileInfo): Response
    {
        $headers = $this->makeCommonHeaders($fileInfo);

        $isMultiRange = \count($request->ranges) > 1;

        if ($isMultiRange) {
            $headers["Content-Type"] = "multipart/byteranges; boundary={$request->boundary}";
        } else {
            $range = $request->ranges[0];
            $headers["Content-Length"] = (string) ($range->end - $range->start + 1);
            $headers["Content-Range"] = "bytes {$range->start}-{$range->end}/{$fileInfo->size}";
            $headers["Content-Type"] = $request->contentType;
        }

        $handle = $this->filesystem->openFile($fileInfo->path, "r");

        if (!$isMultiRange) {
            $range = $request->ranges[0];
            $stream = $this->sendSingleRange($handle, $range->start, $range->end);
        } else {
            $stream = $this->sendMultiRange($handle, $request->contentType, $fileInfo, $request);
        }

        $response = new Response(HttpStatus::PARTIAL_CONTENT, $headers, $stream);
        $response->onDispose($handle->close(...));
        return $response;
    }

    private function sendSingleRange(File $handle, int $startPos, int $endPos): ReadableStream
    {
        $pipeline = Pipeline::fromIterable($this->readRangeFromHandle($handle, $startPos, $endPos));
        return new ReadableIterableStream($pipeline->getIterator());
    }

    private function sendMultiRange(
        File $handle,
        string $mime,
        Internal\FileInformation $fileInfo,
        Internal\ByteRangeRequest $request
    ): ReadableStream {
        $pipeline = Pipeline::fromIterable(function () use ($handle, $mime, $request, $fileInfo): \Generator {
            foreach ($request->ranges as $range) {
                yield \sprintf(
                    "--%s\r\nContent-Type: %s\r\nContent-Range: bytes %d-%d/%d\r\n\r\n",
                    $request->boundary,
                    $mime,
                    $range->start,
                    $range->end,
                    $fileInfo->size,
                );
                yield from $this->readRangeFromHandle($handle, $range->start, $range->end);
                yield "\r\n";
            }
            yield "--{$request->boundary}--";
        });

        return new ReadableIterableStream($pipeline->getIterator());
    }

    private function readRangeFromHandle(File $handle, int $startPos, int $endPos): \Generator
    {
        $bytesRemaining = $endPos - $startPos + 1;
        $handle->seek($startPos);

        while ($bytesRemaining) {
            $length = \min($bytesRemaining, self::READ_CHUNK_SIZE);
            $chunk = $handle->read(length: $length);

            $bytesRemaining -= \strlen((string) $chunk);
            yield $chunk;

            if (\strlen((string) $chunk) < $length) {
                return;
            }
        }
    }

    public function setIndexes(array $indexes): void
    {
        foreach ($indexes as $index) {
            if (!\is_string($index)) {
                throw new \TypeError(\sprintf(
                    "Array of string index filenames required: %s provided",
                    \get_debug_type($index),
                ));
            }
        }

        $this->indexes = \array_values(\array_unique(\array_filter($indexes)));
    }

    public function setUseEtagInode(bool $useInode): void
    {
        $this->useEtagInode = $useInode;
    }

    public function setExpiresPeriod(int $seconds): void
    {
        $this->expiresPeriod = ($seconds < 0) ? 0 : $seconds;
    }

    public function loadMimeFileTypes(string $mimeFile): void
    {
        $mimeFile = \str_replace('\\', '/', $mimeFile);
        $contents = read($mimeFile);
        if ($contents === false) {
            throw new \Exception(
                "Failed loading mime associations from file {$mimeFile}"
            );
        }

        if (!\preg_match_all('#\s*([a-z0-9]+)\s+([a-z0-9\-]+/[a-z0-9\-]+(?:\+[a-z0-9\-]+)?)#i', $contents, $matches)) {
            throw new \Exception(
                "No mime associations found in file: {$mimeFile}"
            );
        }

        $mimeTypes = [];

        foreach ($matches[1] as $key => $value) {
            $mimeTypes[\strtolower($value)] = $matches[2][$key];
        }

        $this->mimeFileTypes = $mimeTypes;
    }

    /**
     * @param array<string, string> $mimeTypes
     */
    public function setMimeTypes(array $mimeTypes): void
    {
        foreach ($mimeTypes as $ext => $type) {
            $ext = \strtolower(\ltrim($ext, '.'));
            $this->mimeTypes[$ext] = $type;
        }
    }

    public function setDefaultMimeType(string $mimeType): void
    {
        if (empty($mimeType)) {
            throw new \Error(
                'Default mime type expects a non-empty string'
            );
        }

        $this->defaultMimeType = $mimeType;
    }

    public function setDefaultTextCharset(string $charset): void
    {
        if (empty($charset)) {
            throw new \Error(
                'Default charset expects a non-empty string'
            );
        }

        $this->defaultCharset = $charset;
    }

    public function setUseAggressiveCacheHeaders(bool $bool): void
    {
        $this->useAggressiveCacheHeaders = $bool;
    }

    public function setAggressiveCacheMultiplier(float $multiplier): void
    {
        if ($multiplier > 0.0 && $multiplier < 1.0) {
            $this->aggressiveCacheMultiplier = $multiplier;
        } else {
            throw new \Error(
                "Aggressive cache multiplier expects a float < 1; {$multiplier} specified"
            );
        }
    }

    public function setCacheEntryTtl(int $seconds): void
    {
        if ($seconds < 1) {
            $seconds = 10;
        }
        $this->cacheEntryTtl = $seconds;
    }

    public function setCacheEntryLimit(int $count): void
    {
        if ($count < 1) {
            $count = 0;
        }
        $this->cacheEntryLimit = $count;
    }

    public function setBufferedFileLimit(int $count): void
    {
        if ($count < 1) {
            $count = 0;
        }
        $this->bufferedFileLimit = $count;
    }

    public function setBufferedFileSizeLimit(int $bytes): void
    {
        if ($bytes < 1) {
            $bytes = 524288;
        }
        $this->bufferedFileSizeLimit = $bytes;
    }

    private function onStart(): void
    {
        $this->running = true;

        if (empty($this->mimeFileTypes)) {
            $this->loadMimeFileTypes(self::DEFAULT_MIME_TYPE_FILE);
        }
    }

    private function onStop(): void
    {
        $this->running = false;
    }
}

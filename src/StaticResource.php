<?php declare(strict_types=1);

namespace Amp\Http\Server\StaticContent;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;

final class StaticResource implements RequestHandler
{
    use ForbidCloning;
    use ForbidSerialization;

    private readonly DocumentRoot $documentRoot;

    private readonly string $path;

    /**
     * @param string $path Path to static resource to serve. This resource will always be served, regardless of the
     * request URI.
     */
    public function __construct(HttpServer $server, ErrorHandler $errorHandler, string $path)
    {
        $path = removeDotPathSegments($path);
        $root = \dirname($path);
        $this->path = '/' . \basename($path);

        $this->documentRoot = new DocumentRoot($server, $errorHandler, $root);
    }

    public function handleRequest(Request $request): Response
    {
        $request->setUri($request->getUri()->withPath($this->path));
        return $this->documentRoot->handleRequest($request);
    }

    public function setMimeType(string $mimeType): void
    {
        if (\preg_match('/\.(?<ext>\w+)$/', $this->path, $matches)) {
            $this->documentRoot->setMimeTypes([$matches['ext'] => $mimeType]);
        }

        $this->documentRoot->setDefaultMimeType($mimeType);
    }

    public function setUseEtagInode(bool $useInode): void
    {
        $this->documentRoot->setUseEtagInode($useInode);
    }

    public function setExpiresPeriod(int $seconds): void
    {
        $this->documentRoot->setExpiresPeriod($seconds);
    }

    public function setTextCharset(string $charset): void
    {
        $this->documentRoot->setDefaultTextCharset($charset);
    }

    public function setUseAggressiveCacheHeaders(bool $bool): void
    {
        $this->documentRoot->setUseAggressiveCacheHeaders($bool);
    }

    public function setAggressiveCacheMultiplier(float $multiplier): void
    {
        $this->documentRoot->setAggressiveCacheMultiplier($multiplier);
    }

    public function setCacheEntryTtl(int $seconds): void
    {
        $this->documentRoot->setCacheEntryTtl($seconds);
    }
}

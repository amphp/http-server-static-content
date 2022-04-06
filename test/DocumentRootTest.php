<?php

namespace Amp\Http\Server\StaticContent\Test;

use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Request;
use Amp\Http\Server\StaticContent\DocumentRoot;
use Amp\Http\Status;
use Amp\PHPUnit\AsyncTestCase;
use Psr\Http\Message\UriInterface as PsrUri;

class DocumentRootTest extends AsyncTestCase
{
    private HttpServer $server;

    private DocumentRoot $root;

    private static function fixturePath(): string
    {
        return \sys_get_temp_dir() . "/amp_http_static_content_test_fixture";
    }

    public function setUp(): void
    {
        parent::setUp();
        $this->server = $this->createServer();
        $this->root = new DocumentRoot($this->server, new DefaultErrorHandler(), self::fixturePath());
        $this->root->onStart($this->server);
    }

    /**
     * Setup a directory we can use as the document root.
     */
    public static function setUpBeforeClass(): void
    {
        $fixtureDir = self::fixturePath();
        if (!\file_exists($fixtureDir) && !\mkdir($fixtureDir)) {
            throw new \RuntimeException(
                "Failed creating temporary test fixture directory: {$fixtureDir}"
            );
        }
        if (!\file_exists($fixtureDir. "/dir") && !\mkdir($fixtureDir . "/dir")) {
            throw new \RuntimeException(
                "Failed creating temporary test fixture directory"
            );
        }
        if (!\file_put_contents($fixtureDir . "/index.htm", "test")) {
            throw new \RuntimeException(
                "Failed creating temporary test fixture file"
            );
        }
        if (!\file_put_contents($fixtureDir . "/svg.svg", "<svg></svg>")) {
            throw new \RuntimeException(
                "Failed creating temporary test fixture file"
            );
        }
    }

    public static function tearDownAfterClass(): void
    {
        $fixtureDir = self::fixturePath();
        if (!@\file_exists($fixtureDir)) {
            return;
        }
        if (\stripos(\PHP_OS, "win") === 0) {
            \system('rd /Q /S "' . $fixtureDir . '"');
        } else {
            \system('/usr/bin/env rm -rf ' . \escapeshellarg($fixtureDir));
        }
    }

    public function createServer(): HttpServer
    {
        $server = $this->createMock(HttpServer::class);
        $server->method('getErrorHandler')
            ->willReturn(new DefaultErrorHandler());

        return $server;
    }

    public function createUri(string $path): PsrUri
    {
        $uri = $this->createMock(PsrUri::class);
        $uri->method('getPath')
            ->willReturn($path);

        return $uri;
    }

    /**
     * @dataProvider provideBadDocRoots
     */
    public function testConstructorThrowsOnInvalidDocRoot(string $badPath): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Document root requires a readable directory');

        $root = new DocumentRoot($this->server, new DefaultErrorHandler(), $badPath);
    }

    public function provideBadDocRoots(): array
    {
        return [
            [self::fixturePath() . "/some-dir-that-doesnt-exist"],
            [self::fixturePath() . "/index.htm"],
        ];
    }

    public function testBasicFileResponse(): void
    {
        foreach ([
            ["/", "test"],
            ["/index.htm", "test"],
            ["/dir/../dir//..//././index.htm", "test"],
        ] as list($path, $contents)) {
            $request = new Request($this->createMock(Client::class), "GET", $this->createUri($path));

            $response = $this->root->handleRequest($request);

            $this->assertSame("text/html; charset=utf-8", $response->getHeader("content-type"));
            $stream = $response->getBody();
            $this->assertSame($contents, $stream->read());
        }
    }

    /**
     * @depends testBasicFileResponse
     * @dataProvider provideRelativePathsAboveRoot
     */
    public function testPathsOnRelativePathAboveRoot(string $relativePath): void
    {
        $request = new Request($this->createMock(Client::class), "GET", $this->createUri($relativePath));

        $response = $this->root->handleRequest($request);
        $stream = $response->getBody();
        $this->assertSame("test", $stream->read());
    }

    public function provideRelativePathsAboveRoot(): array
    {
        return [
            ["/../../../index.htm"],
            ["/dir/../../"],
        ];
    }

    /**
     * @depends testBasicFileResponse
     * @dataProvider provideUnavailablePathsAboveRoot
     */
    public function testUnavailablePathsOnRelativePathAboveRoot(string $relativePath): void
    {
        $request = new Request($this->createMock(Client::class), "GET", $this->createUri($relativePath));

        $response = $this->root->handleRequest($request);
        $this->assertSame(Status::NOT_FOUND, $response->getStatus());
    }

    public function provideUnavailablePathsAboveRoot()
    {
        return [
            ["/../aerys_root_test_fixture/index.htm"],
            ["/aerys_root_test_fixture/../../aerys_root_test_fixture"],
        ];
    }

    /**
     * @depends testBasicFileResponse
     */
    public function testCachedResponse(): void
    {
        $request = new Request($this->createMock(Client::class), "GET", $this->createUri("/index.htm"));

        $response = $this->root->handleRequest($request);

        $this->assertSame("text/html; charset=utf-8", $response->getHeader("content-type"));
        $stream = $response->getBody();
        $this->assertSame("test", $stream->read());
    }

    /**
     * @depends testBasicFileResponse
     */
    public function testDebugModeIgnoresCacheIfCacheControlHeaderIndicatesToDoSo(): void
    {
        $server = $this->createServer();

        $this->root->onStart($server);

        $request = new Request($this->createMock(Client::class), "GET", $this->createUri("/index.htm"), [
            "cache-control" => "no-cache",
        ]);

        $response = $this->root->handleRequest($request);

        $this->assertSame("text/html; charset=utf-8", $response->getHeader("content-type"));
        $stream = $response->getBody();
        $this->assertSame("test", $stream->read());
    }

    /**
     * @depends testDebugModeIgnoresCacheIfCacheControlHeaderIndicatesToDoSo
     */
    public function testDebugModeIgnoresCacheIfPragmaHeaderIndicatesToDoSo(): void
    {
        $request = new Request($this->createMock(Client::class), "GET", $this->createUri("/index.htm"), [
            "cache-control" => "no-cache",
        ]);

        $response = $this->root->handleRequest($request);

        $this->assertSame("text/html; charset=utf-8", $response->getHeader("content-type"));
        $stream = $response->getBody();
        $this->assertSame("test", $stream->read());
    }

    public function testOptionsHeader(): void
    {
        $request = new Request($this->createMock(Client::class), "OPTIONS", $this->createUri("/"));

        $response = $this->root->handleRequest($request);

        $this->assertSame("GET, HEAD, OPTIONS", $response->getHeader('allow'));
        $this->assertSame("bytes", $response->getHeader('accept-ranges'));
    }

    public function testPreconditionFailure(): void
    {
        $this->root->setUseEtagInode(false);

        $request = new Request($this->createMock(Client::class), "GET", $this->createUri("/index.htm"), [
            "if-match" => "any value",
        ]);

        $response = $this->root->handleRequest($request);

        $this->assertSame(Status::PRECONDITION_FAILED, $response->getStatus());
    }

    public function testPreconditionNotModified(): void
    {
        $this->root->setUseEtagInode(false);
        $diskPath = \realpath(self::fixturePath())."/index.htm";
        $etag = \md5($diskPath.\filemtime($diskPath).\filesize($diskPath));

        $request = new Request($this->createMock(Client::class), "GET", $this->createUri("/index.htm"), [
            "if-match" => $etag,
            "if-modified-since" => "2.1.1970",
        ]);

        $response = $this->root->handleRequest($request);

        $this->assertSame(Status::NOT_MODIFIED, $response->getStatus());
        $this->assertSame(\gmdate("D, d M Y H:i:s", \filemtime($diskPath))." GMT", $response->getHeader("last-modified"));
        $this->assertSame($etag, $response->getHeader("etag"));
    }

    public function testPreconditionRangeFail(): void
    {
        $this->root->setUseEtagInode(false);
        $diskPath = \realpath(self::fixturePath())."/index.htm";
        $etag = \md5($diskPath.\filemtime($diskPath).\filesize($diskPath));

        $request = new Request($this->createMock(Client::class), "GET", $this->createUri("/index.htm"), [
            "if-range" => "foo",
        ]);

        $response = $this->root->handleRequest($request);

        $stream = $response->getBody();
        $this->assertSame("test", $stream->read());
    }

    public function testBadRange(): void
    {
        $this->root->setUseEtagInode(false);

        $diskPath = \realpath(self::fixturePath())."/index.htm";
        $etag = \md5($diskPath.\filemtime($diskPath).\filesize($diskPath));

        $request = new Request($this->createMock(Client::class), "GET", $this->createUri("/index.htm"), [
            "if-range" => $etag,
            "range" => "bytes=7-10",
        ]);

        $response = $this->root->handleRequest($request);

        $this->assertSame(Status::RANGE_NOT_SATISFIABLE, $response->getStatus());
        $this->assertSame("*/4", $response->getHeader("content-range"));
    }

    /**
     * @dataProvider provideValidRanges
     */
    public function testValidRange(string $range, callable $validator): void
    {
        $this->root->setUseEtagInode(false);

        $request = new Request($this->createMock(Client::class), "GET", $this->createUri("/index.htm"), [
            "if-range" => "+1 second",
            "range" => "bytes=$range",
        ]);

        $response = $this->root->handleRequest($request);

        $this->assertSame(Status::PARTIAL_CONTENT, $response->getStatus());

        $body = "";
        while (null !== $chunk = $response->getBody()->read()) {
            $body .= $chunk;
        }

        $validator($response->getHeaders(), $body);

        unset($response);
    }

    public function provideValidRanges(): array
    {
        return [
            ["1-2", function ($headers, $body): void {
                $this->assertEquals(2, $headers["content-length"][0]);
                $this->assertEquals("bytes 1-2/4", $headers["content-range"][0]);
                $this->assertEquals("es", $body);
            }],
            ["-0,1-2,2-", function ($headers, $body): void {
                $start = "multipart/byteranges; boundary=";
                $this->assertEquals($start, \substr($headers["content-type"][0], 0, \strlen($start)));
                $boundary = \substr($headers["content-type"][0], \strlen($start));
                foreach ([["3-3", "t"], ["1-2", "es"], ["2-3", "st"]] as list($range, $text)) {
                    $expected = <<<PART
--$boundary\r
Content-Type: text/html; charset=utf-8\r
Content-Range: bytes $range/4\r
\r
$text\r

PART;
                    $this->assertEquals($expected, \substr($body, 0, \strlen($expected)));
                    $body = \substr($body, \strlen($expected));
                }
                $this->assertEquals("--$boundary--", $body);
            }],
        ];
    }

    /**
     * @depends testBasicFileResponse
     */
    public function testMimetypeParsing(): void
    {
        $request = new Request($this->createMock(Client::class), "GET", $this->createUri("/svg.svg"));

        $response = $this->root->handleRequest($request);

        $this->assertSame("image/svg+xml", $response->getHeader("content-type"));
        $stream = $response->getBody();
        $this->assertSame("<svg></svg>", $stream->read());
    }
}

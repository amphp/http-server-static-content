<?php

namespace Amp\Http\Server\StaticContent\Test;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Options;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Server;
use Amp\Http\Server\StaticContent\DocumentRoot;
use Amp\Http\Status;
use Amp\Loop;
use Amp\Promise;
use Amp\Socket;
use League\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface as PsrLogger;

class DocumentRootTest extends TestCase
{
    /** @var \Amp\Loop\Driver */
    private static $loop;

    private static function fixturePath()
    {
        return \sys_get_temp_dir() . "/aerys_root_test_fixture";
    }

    /**
     * Setup a directory we can use as the document root.
     */
    public static function setUpBeforeClass()
    {
        self::$loop = Loop::get();

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

    public static function tearDownAfterClass()
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

    /**
     * Restore original loop driver instance as data providers require the same driver instance to be active as when
     * the data was generated.
     */
    public function setUp()
    {
        Loop::set(self::$loop);
    }

    public function createServer(Options $options = null): Server
    {
        $socket = Socket\listen('127.0.0.1:0');

        $server = new Server(
            [$socket],
            $this->createMock(RequestHandler::class),
            $this->createMock(PsrLogger::class),
            $options
        );

        return $server;
    }

    /**
     * @dataProvider provideBadDocRoots
     * @expectedException \Error
     * @expectedExceptionMessage Document root requires a readable directory
     */
    public function testConstructorThrowsOnInvalidDocRoot($badPath)
    {
        $filesystem = $this->createMock('Amp\File\Driver');
        $root = new DocumentRoot($badPath, $filesystem);
    }

    public function provideBadDocRoots()
    {
        return [
            [self::fixturePath() . "/some-dir-that-doesnt-exist"],
            [self::fixturePath() . "/index.htm"],
        ];
    }

    public function testBasicFileResponse()
    {
        $root = new DocumentRoot(self::fixturePath());

        $server = $this->createServer((new Options)->withDebugMode());

        $root->onStart($server);

        foreach ([
            ["/", "test"],
            ["/index.htm", "test"],
            ["/dir/../dir//..//././index.htm", "test"],
        ] as list($path, $contents)) {
            $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString($path));

            $promise = $root->handleRequest($request);
            /** @var \Amp\Http\Server\Response $response */
            $response = Promise\wait($promise);

            $this->assertSame("text/html; charset=utf-8", $response->getHeader("content-type"));
            $stream = $response->getBody();
            $this->assertSame($contents, Promise\wait($stream->read()));
        }

        // Return so we can test cached responses in the next case
        return $root;
    }

    /**
     * @depends testBasicFileResponse
     * @dataProvider provideRelativePathsAboveRoot
     */
    public function testPathsOnRelativePathAboveRoot(string $relativePath, DocumentRoot $root)
    {
        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString($relativePath));

        $promise = $root->handleRequest($request);
        /** @var \Amp\Http\Server\Response $response */
        $response = Promise\wait($promise);
        $stream = $response->getBody();
        $this->assertSame("test", Promise\wait($stream->read()));
    }

    public function provideRelativePathsAboveRoot()
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
    public function testUnavailablePathsOnRelativePathAboveRoot(string $relativePath, DocumentRoot $root)
    {
        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString($relativePath));

        $promise = $root->handleRequest($request);
        /** @var \Amp\Http\Server\Response $response */
        $response = Promise\wait($promise);
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
    public function testCachedResponse(DocumentRoot $root)
    {
        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/index.htm"));

        $promise = $root->handleRequest($request);
        /** @var \Amp\Http\Server\Response $response */
        $response = Promise\wait($promise);

        $this->assertSame("text/html; charset=utf-8", $response->getHeader("content-type"));
        $stream = $response->getBody();
        $this->assertSame("test", Promise\wait($stream->read()));
    }

    /**
     * @depends testBasicFileResponse
     */
    public function testDebugModeIgnoresCacheIfCacheControlHeaderIndicatesToDoSo(DocumentRoot $root)
    {
        $server = $this->createServer((new Options)->withDebugMode());

        $root->onStart($server);

        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/index.htm"), [
            "cache-control" => "no-cache",
        ]);

        $promise = $root->handleRequest($request);
        /** @var \Amp\Http\Server\Response $response */
        $response = Promise\wait($promise);

        $this->assertSame("text/html; charset=utf-8", $response->getHeader("content-type"));
        $stream = $response->getBody();
        $this->assertSame("test", Promise\wait($stream->read()));

        return $root;
    }

    /**
     * @depends testDebugModeIgnoresCacheIfCacheControlHeaderIndicatesToDoSo
     */
    public function testDebugModeIgnoresCacheIfPragmaHeaderIndicatesToDoSo(DocumentRoot $root)
    {
        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/index.htm"), [
            "cache-control" => "no-cache",
        ]);

        $promise = $root->handleRequest($request);
        /** @var \Amp\Http\Server\Response $response */
        $response = Promise\wait($promise);

        $this->assertSame("text/html; charset=utf-8", $response->getHeader("content-type"));
        $stream = $response->getBody();
        $this->assertSame("test", Promise\wait($stream->read()));

        return $root;
    }

    public function testOptionsHeader()
    {
        $root = new DocumentRoot(self::fixturePath());
        $request = new Request($this->createMock(Client::class), "OPTIONS", Uri\Http::createFromString("/"));

        $promise = $root->handleRequest($request);
        /** @var \Amp\Http\Server\Response $response */
        $response = Promise\wait($promise);

        $this->assertSame("GET, HEAD, OPTIONS", $response->getHeader('allow'));
        $this->assertSame("bytes", $response->getHeader('accept-ranges'));
    }

    public function testPreconditionFailure()
    {
        $root = new DocumentRoot(self::fixturePath());

        $server = $this->createServer((new Options)->withDebugMode());

        $root->setUseEtagInode(false);
        $root->onStart($server);

        $diskPath = \realpath(self::fixturePath())."/index.htm";

        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/index.htm"), [
            "if-match" => "any value",
        ]);

        $promise = $root->handleRequest($request);
        /** @var \Amp\Http\Server\Response $response */
        $response = Promise\wait($promise);

        $this->assertSame(Status::PRECONDITION_FAILED, $response->getStatus());
    }

    public function testPreconditionNotModified()
    {
        $root = new DocumentRoot(self::fixturePath());
        $root->setUseEtagInode(false);
        $diskPath = \realpath(self::fixturePath())."/index.htm";
        $etag = \md5($diskPath.\filemtime($diskPath).\filesize($diskPath));

        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/index.htm"), [
            "if-match" => $etag,
            "if-modified-since" => "2.1.1970",
        ]);

        $promise = $root->handleRequest($request);
        /** @var \Amp\Http\Server\Response $response */
        $response = Promise\wait($promise);

        $this->assertSame(Status::NOT_MODIFIED, $response->getStatus());
        $this->assertSame(\gmdate("D, d M Y H:i:s", \filemtime($diskPath))." GMT", $response->getHeader("last-modified"));
        $this->assertSame($etag, $response->getHeader("etag"));
    }

    public function testPreconditionRangeFail()
    {
        $root = new DocumentRoot(self::fixturePath());
        $root->setUseEtagInode(false);
        $diskPath = \realpath(self::fixturePath())."/index.htm";
        $etag = \md5($diskPath.\filemtime($diskPath).\filesize($diskPath));

        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/index.htm"), [
            "if-range" => "foo",
        ]);

        $promise = $root->handleRequest($request);
        /** @var \Amp\Http\Server\Response $response */
        $response = Promise\wait($promise);

        $stream = $response->getBody();
        $this->assertSame("test", Promise\wait($stream->read()));
    }

    public function testBadRange()
    {
        $root = new DocumentRoot(self::fixturePath());

        $server = $this->createServer((new Options)->withDebugMode());

        $root->setUseEtagInode(false);
        $root->onStart($server);

        $diskPath = \realpath(self::fixturePath())."/index.htm";
        $etag = \md5($diskPath.\filemtime($diskPath).\filesize($diskPath));

        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/index.htm"), [
            "if-range" => $etag,
            "range" => "bytes=7-10",
        ]);

        $promise = $root->handleRequest($request);
        /** @var \Amp\Http\Server\Response $response */
        $response = Promise\wait($promise);

        $this->assertSame(Status::RANGE_NOT_SATISFIABLE, $response->getStatus());
        $this->assertSame("*/4", $response->getHeader("content-range"));
    }

    /**
     * @dataProvider provideValidRanges
     */
    public function testValidRange(string $range, callable $validator)
    {
        Loop::run(function () use ($range, $validator) {
            $root = new DocumentRoot(self::fixturePath());
            $root->setUseEtagInode(false);

            $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/index.htm"), [
                "if-range" => "+1 second",
                "range" => "bytes=$range",
            ]);

            /** @var \Amp\Http\Server\Response $response */
            $response = yield $root->handleRequest($request);

            $this->assertSame(Status::PARTIAL_CONTENT, $response->getStatus());

            $body = "";
            while (null !== $chunk = yield $response->getBody()->read()) {
                $body .= $chunk;
            }

            $validator($response->getHeaders(), $body);

            Loop::stop();
        });
    }

    public function provideValidRanges()
    {
        return [
            ["1-2", function ($headers, $body) {
                $this->assertEquals(2, $headers["content-length"][0]);
                $this->assertEquals("bytes 1-2/4", $headers["content-range"][0]);
                $this->assertEquals("es", $body);
            }],
            ["-0,1-2,2-", function ($headers, $body) {
                $start = "multipart/byteranges; boundary=";
                $this->assertEquals($start, \substr($headers["content-type"][0], 0, \strlen($start)));
                $boundary = \substr($headers["content-type"][0], \strlen($start));
                foreach ([["3-3", "t"], ["1-2", "es"], ["2-3", "st"]] as list($range, $text)) {
                    $expected = <<<PART
--$boundary\r
Content-Type: text/plain; charset=utf-8\r
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
    public function testMimetypeParsing(DocumentRoot $root)
    {
        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/svg.svg"));

        $promise = $root->handleRequest($request);
        /** @var \Amp\Http\Server\Response $response */
        $response = Promise\wait($promise);

        $this->assertSame("image/svg+xml", $response->getHeader("content-type"));
        $stream = $response->getBody();
        $this->assertSame("<svg></svg>", Promise\wait($stream->read()));
    }
}

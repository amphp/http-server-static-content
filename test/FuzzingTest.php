<?php

namespace Amp\Http\Server\StaticContent\Test;

use Amp\ByteStream\Payload;
use Amp\Http\Server\Server;
use Amp\Http\Server\StaticContent\DocumentRoot;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Socket;
use Psr\Log\NullLogger;

class FuzzingTest extends AsyncTestCase
{
    /** @var Socket\Server */
    private static $socket;

    /** @var string */
    private static $documentRoot;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$socket = Socket\listen("127.0.0.1:0");
        self::$documentRoot = \sys_get_temp_dir() . '/amphp-http-server-document-root-' . \bin2hex(\random_bytes(4));

        \mkdir(self::$documentRoot);
    }

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();

        if (\file_exists(self::$documentRoot)) {
            \rmdir(self::$documentRoot);
        }

        self::$socket = null;
    }


    /** @dataProvider provideAttacks */
    public function testDocumentRootBreakout(string $input): \Generator
    {
        $server = new Server([self::$socket], new DocumentRoot(self::$documentRoot), new NullLogger);
        yield $server->start();

        /** @var Socket\ResourceSocket $client */
        $client = yield Socket\connect((string) self::$socket->getAddress());
        yield $client->write("GET {$input} HTTP/1.1\r\nConnection: close\r\nHost: localhost\r\n\r\n");

        $response = yield (new Payload($client))->buffer();
        $this->assertRegExp('(
            HTTP/1\.0\ 400\ Bad\ Request:\ (?:
                invalid\ request\ line|
                authority-form\ only\ valid\ for\ CONNECT\ requests
            )|
            HTTP/1.1\ 404\ Not\ Found|
            ^$
        )x', $response);

        yield $server->stop();
    }

    public function provideAttacks(): array
    {
        $secLists = __DIR__ . '/../vendor/danielmiessler/sec-lists/';
        $contents = \file_get_contents($secLists . 'Fuzzing/UnixAttacks.fuzzdb.txt') . "\n";
        $contents .= \file_get_contents($secLists . 'Fuzzing/Windows-Attacks.fuzzdb.txt');
        $cases = [];

        foreach (\explode("\n", $contents) as $line) {
            $line = \trim($line);
            if ($line === '') {
                continue;
            }

            $cases[] = [$line];

            if ($line[0] !== '/') {
                $cases[] = ['/' . $line];
            }
        }

        return $cases;
    }
}

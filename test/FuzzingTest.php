<?php

namespace Amp\Http\Server\StaticContent\Test;

use Amp\ByteStream;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\HttpSocketServer;
use Amp\Http\Server\StaticContent\DocumentRoot;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Socket;
use Psr\Log\NullLogger;

class FuzzingTest extends AsyncTestCase
{
    private static Socket\SocketServer $socket;

    /** @var string */
    private static string $documentRoot;

    private static HttpSocketServer $server;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$documentRoot = \sys_get_temp_dir() . '/amphp-http-server-document-root-' . \bin2hex(\random_bytes(4));

        \mkdir(self::$documentRoot);

        $server = new HttpSocketServer(new NullLogger);
        $server->expose(new Socket\InternetAddress('127.0.0.1', 0));
        $server->start(new DocumentRoot($server, new DefaultErrorHandler(), self::$documentRoot));

        self::$server = $server;
        self::$socket = $server->getServers()[0] ?? self::fail('Could not get created socket server');
    }

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();

        if (\file_exists(self::$documentRoot)) {
            \rmdir(self::$documentRoot);
        }

        self::$server->stop();
    }


    /** @dataProvider provideAttacks */
    public function testDocumentRootBreakout(string $input): void
    {
        $client = Socket\connect((string) self::$socket->getAddress());
        $client->write("GET {$input} HTTP/1.1\r\nConnection: close\r\nHost: localhost\r\n\r\n");

        $response = ByteStream\buffer($client);
        $this->assertMatchesRegularExpression('(
            HTTP/1\.0\ 400\ Bad\ Request:\ (?:
                invalid\ request\ line|
                invalid\ target|
                authority-form\ only\ valid\ for\ CONNECT\ requests
            )|
            HTTP/1.1\ 404\ Not\ Found|
            ^$
        )x', $response);
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

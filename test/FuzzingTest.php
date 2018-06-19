<?php

namespace Amp\Http\Server\StaticContent\Test;

use Amp\ByteStream\Payload;
use Amp\Http\Server\Server;
use Amp\Http\Server\StaticContent\DocumentRoot;
use Amp\PHPUnit\TestCase;
use Amp\Promise;
use Amp\Socket;
use Psr\Log\NullLogger;

class FuzzingTest extends TestCase
{
    /** @dataProvider provideAttacks */
    public function testDocumentRootBreakout(string $input)
    {
        $socket = Socket\listen("127.0.0.1:0");
        $tempDir = \sys_get_temp_dir() . '/amphp-http-server-document-root-' . \bin2hex(\random_bytes(4));
        \mkdir($tempDir);
        $server = new Server([$socket], new DocumentRoot($tempDir), new NullLogger);
        Promise\wait($server->start());

        /** @var Socket\ClientSocket $client */
        $client = Promise\wait(Socket\connect($socket->getAddress()));
        Promise\wait($client->write("GET {$input} HTTP/1.1\r\nConnection: close\r\nHost: localhost\r\n\r\n"));

        $response = Promise\wait((new Payload($client))->buffer());
        $this->assertRegExp('(
            HTTP/1\.0\ 400\ Bad\ Request:\ (?:
                invalid\ request\ line|
                authority-form\ only\ valid\ for\ CONNECT\ requests
            )|
            HTTP/1.1\ 404\ Not\ Found|
            ^$
        )x', $response);

        Promise\wait($server->stop());
    }

    public function provideAttacks()
    {
        $secLists = __DIR__ . '/../vendor/danielmiessler/SecLists/';
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

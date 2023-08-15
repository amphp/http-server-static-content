<?php declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Amp\ByteStream;
use Amp\Http\HttpStatus;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Http\Server\SocketHttpServer;
use Amp\Http\Server\StaticContent\DocumentRoot;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Socket;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use function Amp\trapSignal;

// This example requires amphp/http-server-router and amphp/log to be installed.
// Run this script, then visit http://localhost:1337/ in your browser.

$logHandler = new StreamHandler(ByteStream\getStdout());
$logHandler->pushProcessor(new PsrLogMessageProcessor());
$logHandler->setFormatter(new ConsoleFormatter());
$logger = new Logger('server');
$logger->pushHandler($logHandler);

$server = SocketHttpServer::createForDirectAccess($logger);
$server->expose(new Socket\InternetAddress("0.0.0.0", 1337));
$server->expose(new Socket\InternetAddress("[::]", 1337));

$errorHandler = new DefaultErrorHandler();

$documentRoot = new DocumentRoot($server, $errorHandler, __DIR__ . '/public');

$router = new Router($server, $logger, $errorHandler);
$router->setFallback($documentRoot);
$router->addRoute('GET', '/', new ClosureRequestHandler(function (Request $request): Response {
    // This can also be in a index.htm file, but we want a demo that uses the router.
    $html = <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
            <title>Example</title>
            <link rel="stylesheet" href="/style.css"/>
        </head>
        
        <body>
            <div>
                Hello, World!
            </div>
        </body>
        </html>
        HTML;

    return new Response(HttpStatus::OK, ['content-type' => 'text/html; charset=utf-8'], $html);
}));

$server->start($router, $errorHandler);

// Await SIGINT or SIGTERM to be received.
$signal = trapSignal([\SIGINT, \SIGTERM]);

$logger->info(sprintf("Received signal %d, stopping HTTP server", $signal));

$server->stop();

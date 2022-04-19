# http-server-static-content

This package provides a static content `RequestHandler` implementations for the [AMPHP HTTP server](https://github.com/amphp/http-server).

## Usage

**`DocumentRoot`** and **`StaticResource`** implement `RequestHandler`.

## Example

```php
<?php

use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\Http\Server\StaticContent\DocumentRoot;
use Amp\Http\Status;

$router = new Amp\Http\Server\Router;
$router->setFallback(new DocumentRoot(__DIR__ . '/public'));
$router->addRoute('GET', '/', new ClosureRequestHandler(function () {
    return new Response(Status::OK, ['content-type' => 'text/plain'], 'Hello, world!');
}));


$server->start($router, new DefaultErrorHandler());
```
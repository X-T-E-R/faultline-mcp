<?php

declare(strict_types=1);

use Faultline\Http\FaultlineHttpController;
use Symfony\Component\HttpFoundation\Request;

if ('cli-server' === \PHP_SAPI) {
    $urlPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', \PHP_URL_PATH);
    if (\is_string($urlPath) && '/' !== $urlPath) {
        $candidate = __DIR__.$urlPath;
        if (is_file($candidate)) {
            return false;
        }
    }
}

require dirname(__DIR__).'/vendor/autoload.php';

$controller = new FaultlineHttpController(dirname(__DIR__));
$request = Request::createFromGlobals();
$response = $controller->handle($request);
$response->send();

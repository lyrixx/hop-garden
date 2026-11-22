<?php

namespace App\Tests\Mcp;

use Http\Discovery\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * A PSR-18 client that hands the requests to the kernel instead of the network: MCP over HTTP,
 * in the test process.
 */
final readonly class KernelHttpClient implements ClientInterface
{
    public function __construct(
        private HttpKernelInterface $kernel,
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $server = [];
        foreach ($request->getHeaders() as $name => $values) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = implode(', ', $values);
        }
        $server['CONTENT_TYPE'] = $request->getHeaderLine('Content-Type');

        $symfonyRequest = Request::create((string) $request->getUri(), $request->getMethod(), server: $server, content: (string) $request->getBody());
        $symfonyResponse = $this->kernel->handle($symfonyRequest, HttpKernelInterface::MAIN_REQUEST);

        if ($symfonyResponse instanceof StreamedResponse) {
            ob_start();
            $symfonyResponse->sendContent();
            $content = (string) ob_get_clean();
        } else {
            $content = (string) $symfonyResponse->getContent();
        }

        $factory = new Psr17Factory();
        $response = $factory->createResponse($symfonyResponse->getStatusCode());
        foreach ($symfonyResponse->headers->all() as $name => $values) {
            $response = $response->withHeader($name, $values);
        }

        return $response->withBody($factory->createStream($content));
    }
}

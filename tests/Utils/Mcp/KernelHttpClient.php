<?php

namespace App\Tests\Utils\Mcp;

use Http\Discovery\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * A PSR-18 client that hands the requests to the kernel instead of the network: MCP over HTTP,
 * in the test process.
 *
 * In production, the worker reaches the MCP server over the network: a request leaves its locale and its security
 * token alone. In the test process, the end of the request resets the locale-aware services, and its firewall leaves
 * its own token: back to the ones of the worker after.
 */
final readonly class KernelHttpClient implements ClientInterface
{
    public function __construct(
        private HttpKernelInterface $kernel,
        private LocaleSwitcher $localeSwitcher,
        private TokenStorageInterface $tokenStorage,
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
        $workerLocale = $this->localeSwitcher->getLocale();
        $workerToken = $this->tokenStorage->getToken();

        try {
            $symfonyResponse = $this->kernel->handle($symfonyRequest, HttpKernelInterface::MAIN_REQUEST);
        } finally {
            $this->localeSwitcher->setLocale($workerLocale);
            $this->tokenStorage->setToken($workerToken);
        }

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

<?php

namespace App\Mcp\Agent;

use App\Mcp\Agent\Exception\NoAgentRunException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The HTTP client of the MCP client "chat" (config/packages/ai.yaml): every request carries the token of the run in
 * progress, so that the MCP server answers in the garden of whoever sent the message. In the tests, the requests go
 * to the kernel (App\Tests\Utils\Mcp\KernelHttpClient, config/services_test.yaml).
 */
final readonly class AgentRunHttpClient implements ClientInterface
{
    public function __construct(
        #[Autowire(service: 'psr18.http_client')]
        private ClientInterface $inner,
        private AgentRunContext $context,
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $token = $this->context->getToken() ?? throw NoAgentRunException::create();

        return $this->inner->sendRequest($request->withHeader('Authorization', 'Bearer ' . $token));
    }
}

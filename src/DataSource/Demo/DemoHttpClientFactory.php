<?php

namespace App\DataSource\Demo;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * In demo mode (APP_DEMO=1), the API clients answer with the recorded responses instead of the network.
 */
final readonly class DemoHttpClientFactory
{
    public function __construct(
        private RecordedResponses $recordedResponses,
        #[Autowire(env: 'bool:APP_DEMO')]
        private bool $demo,
    ) {
    }

    public function create(HttpClientInterface $client, string $baseUri): HttpClientInterface
    {
        if (!$this->demo) {
            return $client;
        }

        return new MockHttpClient($this->recordedResponses, $baseUri);
    }
}

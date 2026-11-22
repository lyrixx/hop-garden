<?php

namespace App\Chat;

use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * The LLM provider of the chat agent comes from the AI_PLATFORM env var.
 */
final readonly class PlatformSelector
{
    /**
     * @param ServiceLocator<PlatformInterface> $platforms
     */
    public function __construct(
        #[AutowireLocator(['anthropic' => new Autowire(service: 'ai.platform.anthropic'), 'openai' => new Autowire(service: 'ai.platform.openai')])]
        private ServiceLocator $platforms,
        #[Autowire(env: 'AI_PLATFORM')]
        private string $platform,
    ) {
    }

    public function select(): PlatformInterface
    {
        if (!$this->platforms->has($this->platform)) {
            throw new \InvalidArgumentException(\sprintf('Unknown AI_PLATFORM "%s", expected one of: "%s".', $this->platform, implode('", "', array_keys($this->platforms->getProvidedServices()))));
        }

        return $this->platforms->get($this->platform);
    }
}

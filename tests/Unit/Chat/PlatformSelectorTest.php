<?php

namespace App\Tests\Unit\Chat;

use App\Chat\PlatformSelector;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class PlatformSelectorTest extends TestCase
{
    public function testThePlatformOfAiPlatform(): void
    {
        $anthropic = MockPlatformFactory::createPlatform('anthropic');
        $openai = MockPlatformFactory::createPlatform('openai');

        self::assertSame($anthropic, (new PlatformSelector(self::platforms($anthropic, $openai), 'anthropic'))->select());
        self::assertSame($openai, (new PlatformSelector(self::platforms($anthropic, $openai), 'openai'))->select());
    }

    public function testAnUnknownPlatform(): void
    {
        $platform = MockPlatformFactory::createPlatform('mock');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Unknown AI_PLATFORM "mistral", expected one of: "anthropic", "openai".');

        (new PlatformSelector(self::platforms($platform, $platform), 'mistral'))->select();
    }

    /**
     * @return ServiceLocator<PlatformInterface>
     */
    private static function platforms(PlatformInterface $anthropic, PlatformInterface $openai): ServiceLocator
    {
        return new ServiceLocator([
            'anthropic' => static fn (): PlatformInterface => $anthropic,
            'openai' => static fn (): PlatformInterface => $openai,
        ]);
    }
}

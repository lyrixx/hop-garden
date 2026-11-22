<?php

namespace App\Tests\Mercure;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\ProtocolVersion;
use Symfony\Component\Mercure\Update;

/**
 * Keeps the updates instead of sending them to a hub.
 */
final class RecordingHub implements HubInterface
{
    /** @var list<Update> */
    private static array $updates = [];

    public function getUrl(): string
    {
        return 'http://frontend/.well-known/mercure';
    }

    public function getPublicUrl(): string
    {
        return 'https://hop-garden.test/.well-known/mercure';
    }

    public function getFactory(): ?TokenFactoryInterface
    {
        return null;
    }

    public function getProtocolVersion(): ProtocolVersion
    {
        return ProtocolVersion::cases()[0];
    }

    public function getCookieName(): string
    {
        return 'mercureAuthorization';
    }

    public function publish(Update $update): string
    {
        self::$updates[] = $update;

        return 'urn:uuid:' . \count(self::$updates);
    }

    public static function reset(): void
    {
        self::$updates = [];
    }

    /**
     * @return list<string> the data of the updates of this topic: Turbo Streams, see TurboStream
     */
    public static function published(string $topic): array
    {
        $data = [];
        foreach (self::$updates as $update) {
            if (\in_array($topic, $update->getTopics(), true)) {
                $data[] = $update->getData();
            }
        }

        return $data;
    }
}

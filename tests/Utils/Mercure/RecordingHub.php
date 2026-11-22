<?php

namespace App\Tests\Utils\Mercure;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\ProtocolVersion;
use Symfony\Component\Mercure\Update;

/**
 * Keeps the updates instead of sending them to a hub. The subscriber cookie is the real one: the token factory of the
 * subscribers, the protocol and the cookie name of config/packages/mercure.yaml.
 */
final class RecordingHub implements HubInterface
{
    /** @var list<Update> */
    private static array $updates = [];

    public function __construct(
        private readonly TokenFactoryInterface $subscriberTokenFactory,
    ) {
    }

    public function getUrl(): string
    {
        return 'http://frontend/.well-known/mercure';
    }

    /**
     * Of the host of the test client: the subscriber cookie is set for it.
     */
    public function getPublicUrl(): string
    {
        return 'http://localhost/.well-known/mercure';
    }

    public function getFactory(): TokenFactoryInterface
    {
        return $this->subscriberTokenFactory;
    }

    public function getProtocolVersion(): ProtocolVersion
    {
        return ProtocolVersion::V1;
    }

    public function getCookieName(): string
    {
        return 'mercure_access_token';
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
        return array_map(static fn (Update $update): string => $update->getData(), self::updates($topic));
    }

    /**
     * @return list<Update>
     */
    public static function updates(string $topic): array
    {
        return array_values(array_filter(self::$updates, static fn (Update $update): bool => \in_array($topic, $update->getTopics(), true)));
    }

    /**
     * @return list<string> every topic published to
     */
    public static function topics(): array
    {
        return array_values(array_unique(array_merge(...array_map(static fn (Update $update): array => $update->getTopics(), self::$updates))));
    }
}

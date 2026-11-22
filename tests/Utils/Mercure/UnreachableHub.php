<?php

namespace App\Tests\Utils\Mercure;

use Symfony\Component\Mercure\Exception\RuntimeException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\ProtocolVersion;
use Symfony\Component\Mercure\Update;

/**
 * A hub that does not answer, as when the Mercure hub is down.
 */
final class UnreachableHub implements HubInterface
{
    public function getPublicUrl(): string
    {
        return 'https://hop-garden.test/.well-known/mercure';
    }

    public function getFactory(): ?TokenFactoryInterface
    {
        return null;
    }

    public function publish(Update $update): string
    {
        throw new RuntimeException('Failed to send an update.');
    }

    public function getProtocolVersion(): ProtocolVersion
    {
        return ProtocolVersion::cases()[0];
    }

    public function getCookieName(): string
    {
        return 'mercureAuthorization';
    }
}

<?php

namespace App\Tests\Unit\Mcp;

use App\Factory\GardenFactory;
use App\Factory\UserFactory;
use App\Mcp\Agent\AgentRunTokens;
use App\Mcp\Agent\Exception\InvalidAgentRunTokenException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\UuidV7;

final class AgentRunTokensTest extends TestCase
{
    public function testItProvesWhoSentTheMessageInWhichGardenAndConversation(): void
    {
        $clock = new MockClock('2026-11-22 10:00');
        $tokens = new AgentRunTokens('the secret', $clock);
        $conversationId = new UuidV7();

        $user = UserFactory::createOne();
        $garden = GardenFactory::createOne(['owner' => $user]);
        $claims = $tokens->verify($tokens->issue($user, $garden, $conversationId));

        self::assertSame([$user->getId()->toRfc4122(), $garden->getId()->toRfc4122(), $conversationId->toRfc4122()], [$claims->userId, $claims->gardenId, $claims->conversationId]);
        self::assertSame('2026-11-22 10:15', $claims->expiresAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i'), 'A quarter of an hour: the queue and a run.');
    }

    public function testItExpires(): void
    {
        $clock = new MockClock('2026-11-22 10:00');
        $tokens = new AgentRunTokens('the secret', $clock);
        $token = self::token($tokens);

        $clock->modify('+15 minutes');

        $this->expectException(InvalidAgentRunTokenException::class);
        $this->expectExceptionMessage('The token of the agent run expired at 2026-11-22T10:15:00+00:00.');
        $tokens->verify($token);
    }

    public function testAnotherSecretOrAChangedClaimIsAForgery(): void
    {
        $clock = new MockClock('2026-11-22 10:00');
        $token = self::token(new AgentRunTokens('another secret', $clock));

        try {
            new AgentRunTokens('the secret', $clock)->verify($token);
            self::fail('Signed with another secret.');
        } catch (InvalidAgentRunTokenException $e) {
            self::assertSame('The token of the agent run was not signed by Hop Garden.', $e->getMessage());
        }

        $tokens = new AgentRunTokens('the secret', $clock);
        [$payload, $signature] = explode('.', self::token($tokens));
        $claims = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($claims);
        $claims['garden'] = (new UuidV7())->toRfc4122();

        $this->expectException(InvalidAgentRunTokenException::class);
        $this->expectExceptionMessage('not signed by Hop Garden');
        $tokens->verify(rtrim(strtr(base64_encode(json_encode($claims, JSON_THROW_ON_ERROR)), '+/', '-_'), '=') . '.' . $signature);
    }

    public function testAnythingElseIsMalformed(): void
    {
        $this->expectException(InvalidAgentRunTokenException::class);
        $this->expectExceptionMessage('The token of the agent run is malformed.');

        new AgentRunTokens('the secret', new MockClock())->verify('Bearer whatever');
    }

    private static function token(AgentRunTokens $tokens): string
    {
        $user = UserFactory::createOne();

        return $tokens->issue($user, GardenFactory::createOne(['owner' => $user]), new UuidV7());
    }
}

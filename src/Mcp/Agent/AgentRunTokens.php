<?php

namespace App\Mcp\Agent;

use App\Account\Entity\User;
use App\Garden\Entity\Garden;
use App\Mcp\Agent\Exception\InvalidAgentRunTokenException;
use App\Mcp\Agent\Model\AgentRunClaims;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\UuidV7;

/**
 * The token of an agent run, the only credential of /_mcp/agent: issued when a request dispatches the run (RunAgent,
 * DraftCareSheet), sent by the worker with each request of the MCP client (AgentRunHttpClient), checked by
 * Security\AgentRunAuthenticator. Its claims in JSON, signed with a key derived from the secret of the application.
 */
final readonly class AgentRunTokens
{
    /**
     * Long enough for the queue and a whole run, with its 20 tool calls; short enough to be worth nothing the day after.
     */
    public const int TTL_IN_SECONDS = 15 * 60;

    private string $key;

    public function __construct(
        #[Autowire('%kernel.secret%')]
        string $secret,
        private ClockInterface $clock,
    ) {
        $this->key = hash_hkdf('sha256', $secret, 32, 'hop-garden agent run token');
    }

    public function issue(User $user, Garden $garden, UuidV7 $conversationId): string
    {
        $payload = self::base64UrlEncode(json_encode([
            'user' => $user->getId(),
            'garden' => $garden->getId(),
            'conversation' => $conversationId->toRfc4122(),
            'exp' => $this->clock->now()->getTimestamp() + self::TTL_IN_SECONDS,
        ], JSON_THROW_ON_ERROR));

        return $payload . '.' . $this->sign($payload);
    }

    /**
     * @throws InvalidAgentRunTokenException
     */
    public function verify(string $token): AgentRunClaims
    {
        $parts = explode('.', $token);
        if (2 !== \count($parts)) {
            throw InvalidAgentRunTokenException::malformed();
        }

        [$payload, $signature] = $parts;
        if (!hash_equals($this->sign($payload), $signature)) {
            throw InvalidAgentRunTokenException::forged();
        }

        $claims = json_decode(self::base64UrlDecode($payload), true);
        if (!\is_array($claims) || !\is_int($claims['user'] ?? null) || !\is_int($claims['garden'] ?? null) || !\is_string($claims['conversation'] ?? null) || !\is_int($claims['exp'] ?? null) || !UuidV7::isValid($claims['conversation'])) {
            throw InvalidAgentRunTokenException::malformed();
        }

        $claims = new AgentRunClaims($claims['user'], $claims['garden'], $claims['conversation'], new \DateTimeImmutable('@' . $claims['exp']));
        if ($claims->hasExpired($this->clock->now())) {
            throw InvalidAgentRunTokenException::expired($claims->expiresAt);
        }

        return $claims;
    }

    private function sign(string $payload): string
    {
        return self::base64UrlEncode(hash_hmac('sha256', $payload, $this->key, true));
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }
}

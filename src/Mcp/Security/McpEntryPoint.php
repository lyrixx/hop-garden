<?php

namespace App\Mcp\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * The 401 of both MCP servers (config/packages/security.yaml), never a redirection to the login: a Bearer challenge,
 * as OAuth 2.0 (RFC 6750) words it. /_mcp answers a token of an external client: none until OAuth (phase 3), whose
 * challenge adds the "resource_metadata" of the protected resource (see .claude/tmp/mcp-oauth-study.md). /_mcp/agent
 * answers the token of an agent run only.
 */
final readonly class McpEntryPoint implements AuthenticationEntryPointInterface
{
    public const string AGENT_PATH = '/_mcp/agent';

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return self::unauthorized($request, null);
    }

    /**
     * @param ?string $invalidToken why the token sent is refused, null when there was none
     */
    public static function unauthorized(Request $request, ?string $invalidToken): JsonResponse
    {
        $challenge = 'Bearer realm="Hop Garden"';
        if (null !== $invalidToken) {
            $challenge .= ', error="invalid_token", error_description="' . addcslashes($invalidToken, '"\\') . '"';
        }

        $description = $invalidToken ?? (str_starts_with($request->getPathInfo(), self::AGENT_PATH)
            ? 'Only the chat agent of Hop Garden calls this MCP server, with the token of its run.'
            : 'This MCP server needs an access token: OAuth comes next. Until then, run it over STDIO with "castor mcp:stdio --user=<email>".');

        return new JsonResponse(
            ['error' => null !== $invalidToken ? 'invalid_token' : 'unauthorized', 'error_description' => $description],
            Response::HTTP_UNAUTHORIZED,
            ['WWW-Authenticate' => $challenge],
        );
    }
}

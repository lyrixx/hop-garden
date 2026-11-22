<?php

namespace App\Garden;

use App\Garden\Entity\Garden;
use App\Garden\Exception\NoCurrentGardenException;

/**
 * The garden a request works in: the only way to read or change it (App\Garden\UserCurrentGarden). Of the member
 * logged in, chosen in the menu; or the one a stateless request is bound to, by an attribute of its security token
 * (TOKEN_ATTRIBUTE): the agent run of the chat on /_mcp/agent, "app:mcp:stdio --user", and the OAuth access token of an
 * external MCP client in phase 3. The worker has neither: its messages carry their garden.
 */
interface CurrentGarden
{
    /**
     * The id of the garden a security token is bound to, a UUID in RFC 4122: set by the authenticator that creates it,
     * never another.
     */
    public const string TOKEN_ATTRIBUTE = 'garden_id';

    /**
     * @throws NoCurrentGardenException a member without garden yet, a visitor, or a token bound to a garden they left
     */
    public function get(): Garden;

    public function find(): ?Garden;

    /**
     * For the rest of the session. The caller checks GARDEN_VIEW first.
     */
    public function switchTo(Garden $garden): void;
}

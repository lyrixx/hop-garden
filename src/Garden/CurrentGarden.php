<?php

namespace App\Garden;

use App\Garden\Entity\Garden;
use App\Garden\Exception\NoCurrentGardenException;

/**
 * The garden a request works in: the only way to read it (App\Garden\UserCurrentGarden). On the website, the one of its
 * URL (/g/{garden}/..., checked by App\Garden\GardenOfTheUrl); or the one a stateless request is bound to, by an
 * attribute of its security token (TOKEN_ATTRIBUTE): the agent run of the chat on /_mcp/agent, "app:mcp:stdio --user",
 * and the OAuth access token of an external MCP client in phase 3. None on a page out of a garden (the account...),
 * whose navigation shows the last one (App\Garden\LastGarden), nor in the worker: its messages carry their garden.
 */
interface CurrentGarden
{
    /**
     * The id of the garden a security token is bound to, a UUID in RFC 4122: set by the authenticator that creates it,
     * never another.
     */
    public const string TOKEN_ATTRIBUTE = 'garden_id';

    /**
     * @throws NoCurrentGardenException out of a garden, or a token bound to a garden its member left
     */
    public function get(): Garden;

    public function find(): ?Garden;
}

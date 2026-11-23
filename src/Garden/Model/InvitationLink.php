<?php

namespace App\Garden\Model;

use App\Garden\Entity\Invitation;

/**
 * An invitation and the token of its new link, which only its email and the page of the members show, once: the
 * database keeps its hash.
 */
final readonly class InvitationLink
{
    public function __construct(
        public Invitation $invitation,
        #[\SensitiveParameter]
        public string $token,
    ) {
    }
}

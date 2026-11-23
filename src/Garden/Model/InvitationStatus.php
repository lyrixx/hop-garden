<?php

namespace App\Garden\Model;

/**
 * Where an invitation stands (App\Garden\Entity\Invitation::statusAt()): only a pending one lets somebody in.
 */
enum InvitationStatus: string
{
    case Pending = 'pending';
    case Expired = 'expired';
    case Accepted = 'accepted';
    case Revoked = 'revoked';
}

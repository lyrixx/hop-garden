<?php

namespace App\Garden\Security;

use App\Mcp\Agent\Security\AgentRunAuthenticator;
use App\Plant\Entity\Plant;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * The human validates, always: a member who may edit the care sheet, on the card (PlantController::validate()), or an
 * external MCP client on their behalf, once they confirmed (confirm_plant). Never the chat agent, whose run token
 * (AgentRunAuthenticator) gets no tool that validates anyway.
 *
 * @extends Voter<string, Plant>
 */
final class PlantValidationVoter extends Voter
{
    public const string VALIDATE = 'PLANT_VALIDATE';

    public function __construct(
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::VALIDATE === $attribute && $subject instanceof Plant;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        if ($token->hasAttribute(AgentRunAuthenticator::TOKEN_ATTRIBUTE)) {
            return false;
        }

        return $this->accessDecisionManager->decide($token, [GardenResourceVoter::PLANT_EDIT], $subject);
    }
}

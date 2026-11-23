<?php

namespace App\Tests\Utils\Chat;

use App\Account\Entity\User;
use App\Chat\Message\RunAgent;
use App\Garden\Entity\Garden;
use App\Garden\Entity\Planting;
use App\Garden\Message\DraftCareSheet;
use App\Mcp\Agent\AgentRunTokens;
use App\Mercure\Topics;
use App\Story\DefaultGardenStory;
use App\Tests\Utils\Mercure\ChatStreams;
use Symfony\Component\Uid\UuidV7;

/**
 * The messages of the worker as the controllers dispatch them, with the token of their run, and the Turbo Streams the
 * worker pushes for them: by default from the owner of the garden of the test, in that garden.
 */
trait AgentRunsTrait
{
    private function runAgentMessage(UuidV7|string $conversationId, string $text, string $locale = 'en', ?User $member = null, ?Garden $garden = null): RunAgent
    {
        $garden ??= self::defaultGarden();
        $member ??= $garden->getOwner();
        $id = $conversationId instanceof UuidV7 ? $conversationId : UuidV7::fromString($conversationId);

        return new RunAgent($member->getId()->toRfc4122(), $garden->getId()->toRfc4122(), $id->toRfc4122(), $text, $locale, self::getContainer()->get(AgentRunTokens::class)->issue($member, $garden, $id));
    }

    /**
     * Of the attempt in progress of the planting, by the owner of its garden, unless told.
     */
    private function draftCareSheetMessage(Planting $planting, string $locale = 'en', ?string $conversationId = null, ?User $member = null): DraftCareSheet
    {
        $garden = $planting->getGarden();
        $member ??= $garden->getOwner();
        $conversationId ??= ($planting->getConversationId() ?? self::fail('The planting is not drafted.'))->toRfc4122();

        return new DraftCareSheet($planting->getId()->toRfc4122(), $member->getId()->toRfc4122(), $garden->getId()->toRfc4122(), $conversationId, $locale, self::getContainer()->get(AgentRunTokens::class)->issue($member, $garden, UuidV7::fromString($conversationId)));
    }

    private function conversationTopic(UuidV7|string $conversationId, ?User $member = null): string
    {
        return self::getContainer()->get(Topics::class)->conversation($member ?? self::defaultGarden()->getOwner(), $conversationId instanceof UuidV7 ? $conversationId->toRfc4122() : $conversationId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function conversationStreams(UuidV7|string $conversationId, ?User $member = null): array
    {
        return ChatStreams::of($this->conversationTopic($conversationId, $member));
    }

    private function draftingTopic(Planting $planting, ?string $conversationId = null): string
    {
        return self::getContainer()->get(Topics::class)->drafting($planting->getGarden(), $conversationId ?? ($planting->getConversationId() ?? self::fail('The planting is not drafted.'))->toRfc4122());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function draftingStreams(Planting $planting, ?string $conversationId = null): array
    {
        return ChatStreams::of($this->draftingTopic($planting, $conversationId));
    }

    private static function defaultGarden(): Garden
    {
        $garden = DefaultGardenStory::get('garden');
        self::assertInstanceOf(Garden::class, $garden);

        return $garden;
    }
}

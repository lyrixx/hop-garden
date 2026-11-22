<?php

namespace App\Tests\Integration\Garden;

use App\Account\Entity\User;
use App\Garden\CurrentGarden;
use App\Garden\Entity\Garden;
use App\Garden\Security\GardenResourceVoter;
use App\Garden\Security\GardenVoter;
use App\Garden\Security\PlantValidationVoter;
use App\Mcp\Agent\Security\AgentRunAuthenticator;
use App\Reminder\NotificationRepository;
use App\Tests\Utils\Garden\TwoGardens;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/**
 * The voters, with tokens bound to a garden as the MCP servers have them: refused by default, the garden of the
 * resource first.
 */
final class VotersTest extends KernelTestCase
{
    private TwoGardens $gardens;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->gardens = TwoGardens::create(self::getContainer());
    }

    public function testTheMembersViewAndEditAGardenItsOwnerManagesIt(): void
    {
        $allotment = $this->gardens->allotment;

        self::assertSame([true, true, true], $this->decisions($this->gardens->alice, $allotment, [GardenVoter::VIEW, GardenVoter::EDIT, GardenVoter::MANAGE], $allotment));
        self::assertSame([true, true, false], $this->decisions($this->gardens->bob, $allotment, [GardenVoter::VIEW, GardenVoter::EDIT, GardenVoter::MANAGE], $allotment));
        self::assertSame([false, false, false], $this->decisions($this->gardens->carol, $this->gardens->balcony, [GardenVoter::VIEW, GardenVoter::EDIT, GardenVoter::MANAGE], $allotment));
        self::assertSame([false], $this->decisions($this->gardens->alice, $this->gardens->balcony, [GardenVoter::VIEW], $allotment), 'A token bound to another garden gets no other one.');
    }

    public function testWhatBelongsToAGardenIsOfItsMembersInItOnly(): void
    {
        $resources = [
            GardenResourceVoter::PLANT_VIEW => $this->gardens->rose,
            GardenResourceVoter::PLANT_EDIT => $this->gardens->draft,
            GardenResourceVoter::PLANTING_VIEW => $this->gardens->gate,
            GardenResourceVoter::PLANTING_EDIT => $this->gardens->gate,
            GardenResourceVoter::SPACE_VIEW => $this->gardens->terrace,
            GardenResourceVoter::SPACE_EDIT => $this->gardens->terrace,
            GardenResourceVoter::REMINDER_EDIT => $this->gardens->rose->getReminders()[0],
        ];

        foreach ($resources as $attribute => $resource) {
            self::assertSame([true], $this->decisions($this->gardens->bob, $this->gardens->allotment, [$attribute], $resource), $attribute);
            self::assertSame([false], $this->decisions($this->gardens->carol, $this->gardens->balcony, [$attribute], $resource), $attribute);
            self::assertSame([false], $this->decisions($this->gardens->carol, $this->gardens->allotment, [$attribute], $resource), $attribute . ': bound to a garden she is no member of.');
        }
        self::assertSame([false], $this->decisions($this->gardens->bob, $this->gardens->allotment, [GardenResourceVoter::PLANT_VIEW], $this->gardens->lavender), 'Hers.');
        self::assertSame([false], $this->decisions($this->gardens->bob, $this->gardens->allotment, ['PLANT_DELETE'], $this->gardens->rose), 'Unknown: refused.');
    }

    public function testANotificationIsOfItsRecipientOnly(): void
    {
        $notifications = self::getContainer()->get(NotificationRepository::class);
        $toBob = $notifications->findLatest($this->gardens->bob, $this->gardens->allotment)[0];

        self::assertSame([true], $this->decisions($this->gardens->bob, $this->gardens->allotment, [GardenResourceVoter::NOTIFICATION_VIEW], $toBob));
        self::assertSame([false], $this->decisions($this->gardens->alice, $this->gardens->allotment, [GardenResourceVoter::NOTIFICATION_VIEW], $toBob), 'Alice has her own.');
    }

    public function testTheHumanValidatesNeverTheAgent(): void
    {
        self::assertSame([true], $this->decisions($this->gardens->bob, $this->gardens->allotment, [PlantValidationVoter::VALIDATE], $this->gardens->draft), 'A member.');
        self::assertSame([false], $this->decisions($this->gardens->bob, $this->gardens->allotment, [PlantValidationVoter::VALIDATE], $this->gardens->draft, agentRun: true), 'The agent of his chat.');
        self::assertSame([false], $this->decisions($this->gardens->carol, $this->gardens->balcony, [PlantValidationVoter::VALIDATE], $this->gardens->draft));
    }

    /**
     * @param list<string> $attributes
     *
     * @return list<bool>
     */
    private function decisions(User $user, Garden $garden, array $attributes, object $subject, bool $agentRun = false): array
    {
        $token = new PostAuthenticationToken($user, 'mcp', $user->getRoles());
        $token->setAttribute(CurrentGarden::TOKEN_ATTRIBUTE, $garden->getId());
        if ($agentRun) {
            $token->setAttribute(AgentRunAuthenticator::TOKEN_ATTRIBUTE, '0196a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b');
        }
        self::getContainer()->get(TokenStorageInterface::class)->setToken($token);

        return array_map(static fn (string $attribute): bool => self::getContainer()->get(AuthorizationCheckerInterface::class)->isGranted($attribute, $subject), $attributes);
    }
}

<?php

namespace App\Reminder\Entity;

use App\Plant\Entity\Plant;
use App\Reminder\Model\ReminderType;
use App\Reminder\ReminderRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ReminderRepository::class)]
#[ORM\UniqueConstraint(fields: ['plant', 'type'])]
class Reminder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private bool $enabled = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastTriggeredAt = null;

    /**
     * Last frost day of the cold episode already announced: one frost alert per plant and per episode.
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $frostEpisodeUntil = null;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'reminders')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Plant $plant,
        #[ORM\Column]
        private ReminderType $type,
    ) {
    }

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function disable(): void
    {
        $this->enabled = false;
    }

    public function wasTriggeredInMonthOf(\DateTimeImmutable $date): bool
    {
        return null !== $this->lastTriggeredAt && $this->lastTriggeredAt->format('Y-m') === $date->format('Y-m');
    }

    public function markTriggered(\DateTimeImmutable $at): void
    {
        $this->lastTriggeredAt = $at;
    }

    /**
     * @return bool true when these frost days start a new cold episode, false when they extend the announced one
     */
    public function recordFrostEpisode(\DateTimeImmutable $firstFrostDay, \DateTimeImmutable $lastFrostDay, \DateTimeImmutable $at): bool
    {
        $firstFrostDay = $firstFrostDay->setTime(0, 0);
        $lastFrostDay = $lastFrostDay->setTime(0, 0);

        $continuesEpisode = null !== $this->frostEpisodeUntil && $firstFrostDay <= $this->frostEpisodeUntil->modify('+1 day');

        if ($continuesEpisode) {
            if ($lastFrostDay > $this->frostEpisodeUntil) {
                $this->frostEpisodeUntil = $lastFrostDay;
            }

            return false;
        }

        $this->frostEpisodeUntil = $lastFrostDay;
        $this->lastTriggeredAt = $at;

        return true;
    }

    public function getId(): int
    {
        return $this->id ?? throw new \LogicException('The reminder has not been persisted yet.');
    }

    public function getPlant(): Plant
    {
        return $this->plant;
    }

    public function getType(): ReminderType
    {
        return $this->type;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getLastTriggeredAt(): ?\DateTimeImmutable
    {
        return $this->lastTriggeredAt;
    }

    public function getFrostEpisodeUntil(): ?\DateTimeImmutable
    {
        return $this->frostEpisodeUntil;
    }
}

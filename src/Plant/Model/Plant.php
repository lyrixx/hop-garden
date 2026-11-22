<?php

namespace App\Plant\Model;

use App\Plant\Exception\PlantAlreadyValidatedException;
use App\Plant\Exception\PlantNotValidatedException;
use App\Plant\PlantRepository;
use App\Reminder\Model\Reminder;
use App\Reminder\Model\ReminderType;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PlantRepository::class)]
#[ORM\Index(fields: ['status'])]
class Plant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private PlantStatus $status = PlantStatus::Draft;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $validatedAt = null;

    /** @var Collection<int, Reminder> */
    #[ORM\OneToMany(targetEntity: Reminder::class, mappedBy: 'plant', cascade: ['persist'], orphanRemoval: true)]
    private Collection $reminders;

    private function __construct(
        #[ORM\Column]
        private string $commonName,
        #[ORM\Column]
        private string $scientificName,
        #[ORM\Column]
        private string $family,
        #[ORM\Embedded(columnPrefix: 'care_')]
        private CareSheet $careSheet,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(nullable: true)]
        private ?int $trefleId,
        #[ORM\Column(length: 2048, nullable: true)]
        private ?string $imageUrl,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $notes,
    ) {
        $this->reminders = new ArrayCollection();
    }

    /**
     * A draft only: no reminder and no recommendation until the user validates it.
     */
    public static function propose(
        string $commonName,
        string $scientificName,
        string $family,
        CareSheet $careSheet,
        \DateTimeImmutable $createdAt,
        ?int $trefleId = null,
        ?string $imageUrl = null,
        ?string $notes = null,
    ): self {
        return new self($commonName, $scientificName, $family, $careSheet, $createdAt, $trefleId, $imageUrl, $notes);
    }

    public function validate(\DateTimeImmutable $validatedAt): void
    {
        if ($this->isValidated()) {
            throw PlantAlreadyValidatedException::withId($this->getId());
        }

        $this->status = PlantStatus::Validated;
        $this->validatedAt = $validatedAt;
    }

    /**
     * The user reviews a draft before validating it, or corrects a validated plant, which stays validated.
     */
    public function revise(string $commonName, CareSheet $careSheet, ?string $notes): void
    {
        $this->commonName = $commonName;
        $this->careSheet = $careSheet;
        $this->notes = $notes;
    }

    public function enableReminder(ReminderType $type): Reminder
    {
        if (!$this->isValidated()) {
            throw PlantNotValidatedException::forReminders($this->getId());
        }

        $reminder = $this->findReminder($type);
        if (null === $reminder) {
            $reminder = new Reminder($this, $type);
            $this->reminders->add($reminder);
        }

        $reminder->enable();

        return $reminder;
    }

    public function disableReminder(ReminderType $type): void
    {
        if (!$this->isValidated()) {
            throw PlantNotValidatedException::forReminders($this->getId());
        }

        $this->findReminder($type)?->disable();
    }

    public function hasEnabledReminder(ReminderType $type): bool
    {
        return $this->findReminder($type)?->isEnabled() ?? false;
    }

    /**
     * @return list<ReminderType>
     */
    public function getEnabledReminderTypes(): array
    {
        return array_values(array_filter(
            ReminderType::cases(),
            $this->hasEnabledReminder(...),
        ));
    }

    public function getId(): int
    {
        return $this->id ?? throw new \LogicException('The plant has not been persisted yet.');
    }

    public function getCommonName(): string
    {
        return $this->commonName;
    }

    public function getScientificName(): string
    {
        return $this->scientificName;
    }

    public function getFamily(): string
    {
        return $this->family;
    }

    public function getTrefleId(): ?int
    {
        return $this->trefleId;
    }

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function getStatus(): PlantStatus
    {
        return $this->status;
    }

    public function isValidated(): bool
    {
        return PlantStatus::Validated === $this->status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getValidatedAt(): ?\DateTimeImmutable
    {
        return $this->validatedAt;
    }

    public function getCareSheet(): CareSheet
    {
        return $this->careSheet;
    }

    /**
     * @return list<Reminder>
     */
    public function getReminders(): array
    {
        return array_values($this->reminders->toArray());
    }

    private function findReminder(ReminderType $type): ?Reminder
    {
        foreach ($this->reminders as $reminder) {
            if ($reminder->getType() === $type) {
                return $reminder;
            }
        }

        return null;
    }
}

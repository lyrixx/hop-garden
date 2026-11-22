<?php

namespace App\Garden\Model;

use App\Garden\Exception\CareSheetAlreadyLinkedException;
use App\Garden\PlantingRepository;
use App\Plant\Model\Plant;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * A plant planted somewhere in the garden: what the user sees there, and the care they gave it. The care sheet of
 * its species lives in the Plant it is linked to.
 */
#[ORM\Entity(repositoryClass: PlantingRepository::class)]
class Planting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @var list<array{task: string, done_on: string}> */
    #[ORM\Column(type: Types::JSON)]
    private array $careRecords = [];

    /**
     * The care sheet of its species: a draft until the user validates it.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Plant $plant = null;

    /**
     * The conversation of the agent drafting its care sheet: a new one on each attempt.
     */
    #[ORM\Column(nullable: true)]
    private ?string $conversationId = null;

    /**
     * The last attempt ended without a draft: the user may retry.
     */
    #[ORM\Column]
    private bool $draftingFailed = false;

    public function __construct(
        #[ORM\Column]
        private string $name,
        /**
         * Where it grows, with its exposure, its soil and its neighbours. Unknown until the user tells: a plant
         * validated from the chat joins the garden without it.
         */
        #[ORM\ManyToOne(inversedBy: 'plantings')]
        #[ORM\JoinColumn(onDelete: 'SET NULL')]
        private ?Space $space,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $diseases,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(nullable: true)]
        private ?int $trefleId = null,
        #[ORM\Column(nullable: true)]
        private ?string $scientificName = null,
        #[ORM\Column(nullable: true)]
        private ?string $photoFilename = null,
    ) {
        $space?->welcome($this);
    }

    /**
     * A plant validated from the chat, or by an external MCP client: where and how it grows is left to the user.
     */
    public static function forValidatedPlant(Plant $plant): self
    {
        $validatedAt = $plant->getValidatedAt() ?? throw new \LogicException(\sprintf('Plant %d is not validated.', $plant->getId()));

        $planting = new self($plant->getCommonName(), null, null, $validatedAt, $plant->getTrefleId(), $plant->getScientificName());
        $planting->linkTo($plant);

        return $planting;
    }

    /**
     * What the user sees in the garden. The species stays: its care sheet is linked to it.
     */
    public function revise(string $name, ?Space $space, ?string $diseases): void
    {
        $this->name = $name;
        $this->moveTo($space);
        $this->diseases = $diseases;
    }

    public function replacePhoto(string $photoFilename): void
    {
        $this->photoFilename = $photoFilename;
    }

    /**
     * The same care on the same day is recorded once: the edit form sends back the dates already known.
     */
    public function recordCare(CareTask $task, \DateTimeImmutable $doneOn): void
    {
        $record = ['task' => $task->value, 'done_on' => $doneOn->format('Y-m-d')];
        foreach ($this->careRecords as $recorded) {
            if ($recorded['task'] === $record['task'] && $recorded['done_on'] === $record['done_on']) {
                return;
            }
        }

        $this->careRecords[] = $record;

        usort($this->careRecords, static fn (array $a, array $b): int => $a['done_on'] <=> $b['done_on']);
    }

    /**
     * The plant whose care sheet applies here: the draft the agent proposed, or a validated plant of the same species.
     */
    public function linkTo(Plant $plant): void
    {
        $this->plant = $plant;
        $this->draftingFailed = false;
    }

    /**
     * The agent drafts its care sheet in this conversation; a previous attempt no longer counts.
     */
    public function startDrafting(UuidV7 $conversationId): void
    {
        if (null !== $this->plant) {
            throw CareSheetAlreadyLinkedException::forPlanting($this->getId());
        }

        $this->conversationId = $conversationId->toRfc4122();
        $this->draftingFailed = false;
    }

    public function failDrafting(): void
    {
        $this->draftingFailed = true;
    }

    public function isDrafting(): bool
    {
        return null === $this->plant && null !== $this->conversationId && !$this->draftingFailed;
    }

    /**
     * Whether this conversation is the attempt in progress: a retry, or a link meanwhile, makes an older one stale.
     */
    public function isDraftedIn(string $conversationId): bool
    {
        return $this->isDrafting() && $this->conversationId === $conversationId;
    }

    public function hasDraftingFailed(): bool
    {
        return null === $this->plant && $this->draftingFailed;
    }

    public function lastDone(CareTask $task): ?\DateTimeImmutable
    {
        $last = null;
        foreach ($this->getCareRecords() as $record) {
            if ($record->task === $task && (null === $last || $record->doneOn > $last)) {
                $last = $record->doneOn;
            }
        }

        return $last;
    }

    public function getId(): int
    {
        return $this->id ?? throw new \LogicException('The planting has not been persisted yet.');
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getTrefleId(): ?int
    {
        return $this->trefleId;
    }

    public function getScientificName(): ?string
    {
        return $this->scientificName;
    }

    public function getPhotoFilename(): ?string
    {
        return $this->photoFilename;
    }

    public function getSpace(): ?Space
    {
        return $this->space;
    }

    public function getDiseases(): ?string
    {
        return $this->diseases;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getPlant(): ?Plant
    {
        return $this->plant;
    }

    public function getConversationId(): ?string
    {
        return $this->conversationId;
    }

    /**
     * @return list<CareRecord> oldest first
     */
    public function getCareRecords(): array
    {
        return array_map(
            static fn (array $record): CareRecord => new CareRecord(CareTask::from($record['task']), new \DateTimeImmutable($record['done_on'])),
            $this->careRecords,
        );
    }

    /**
     * @return list<self> the other plantings of its space, by name
     */
    public function getNeighbours(): array
    {
        return array_values(array_filter($this->space?->getPlantings() ?? [], fn (self $planting): bool => $planting !== $this));
    }

    /**
     * Both sides of the relation stay in sync: its neighbours are read from the space.
     */
    private function moveTo(?Space $space): void
    {
        if ($space === $this->space) {
            return;
        }

        $this->space?->release($this);
        $this->space = $space;
        $space?->welcome($this);
    }
}

<?php

namespace App\Factory;

use App\Garden\Entity\Planting;
use App\Garden\Entity\Space;
use App\Garden\Model\CareTask;
use App\Plant\Entity\Plant;
use App\Story\DefaultGardenStory;
use Symfony\Component\Uid\UuidV7;
use Zenstruck\Foundry\Factory;
use Zenstruck\Foundry\Object\Instantiator;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * A planting the user added to the garden, without space nor care sheet unless told. In the garden of its space or of
 * its care sheet, else in the default garden of the test (DefaultGardenStory). Its slug comes from its name, unless told.
 *
 * @extends PersistentObjectFactory<Planting>
 */
final class PlantingFactory extends PersistentObjectFactory
{
    use FreeSlug;

    public static function class(): string
    {
        return Planting::class;
    }

    public function inSpace(Space|SpaceFactory $space): static
    {
        return $this->with(['space' => $space]);
    }

    /**
     * Its care sheet: the plant of its species, a draft or a validated one.
     */
    public function linkedTo(Plant|PlantFactory $plant): static
    {
        return $this->with(['plant' => $plant]);
    }

    /**
     * Named after the plant whose care sheet it follows, with its species, and planted when that plant was validated
     * (created, for a draft).
     */
    public function of(Plant $plant): static
    {
        return $this->linkedTo($plant)->with([
            'name' => $plant->getCommonName(),
            'createdAt' => $plant->getValidatedAt() ?? $plant->getCreatedAt(),
            'trefleId' => $plant->getTrefleId(),
            'scientificName' => $plant->getScientificName(),
        ]);
    }

    public function withCareRecord(CareTask $task, \DateTimeImmutable $doneOn): static
    {
        return $this->afterInstantiate(static function (Planting $planting) use ($task, $doneOn): void {
            $planting->recordCare($task, $doneOn);
        });
    }

    /**
     * The agent drafts its care sheet, in a new conversation.
     */
    public function drafting(): static
    {
        return $this->afterInstantiate(static function (Planting $planting): void {
            $planting->startDrafting(new UuidV7());
        });
    }

    /**
     * The agent ended without draft: the user may retry.
     */
    public function draftingFailed(): static
    {
        return $this->drafting()->afterInstantiate(static function (Planting $planting): void {
            $planting->failDrafting();
        });
    }

    protected function defaults(): array
    {
        return [
            'name' => 'Bigleaf hydrangea',
            'space' => null,
            'diseases' => null,
            'createdAt' => new \DateTimeImmutable('2026-11-01'),
        ];
    }

    protected function initialize(): static
    {
        // The plant is no argument of the constructor: Planting::linkTo() takes it
        return $this
            ->beforeInstantiate(static function (array $attributes): array {
                // Made first, for their garden
                foreach (['space', 'plant'] as $field) {
                    if (($attributes[$field] ?? null) instanceof Factory) {
                        $attributes[$field] = $attributes[$field]->create();
                    }
                }
                $space = $attributes['space'] ?? null;
                $plant = $attributes['plant'] ?? null;
                $attributes['garden'] = self::gardenOf($attributes['garden'] ?? ($space instanceof Space ? $space->getGarden() : ($plant instanceof Plant ? $plant->getGarden() : DefaultGardenStory::get('garden'))));
                $attributes['slug'] ??= self::freeSlug($attributes['name'] ?? null, static fn (): array => array_map(static fn (Planting $planting): string => $planting->getSlug(), static::repository()->findBy(['garden' => $attributes['garden']])), $attributes['garden']);

                return $attributes;
            })
            ->instantiateWith(Instantiator::withConstructor()->allowExtra('plant'))
            ->afterInstantiate(static function (Planting $planting, array $attributes): void {
                $plant = $attributes['plant'] ?? null;
                if ($plant instanceof Plant) {
                    $planting->linkTo($plant);
                }
            })
        ;
    }
}

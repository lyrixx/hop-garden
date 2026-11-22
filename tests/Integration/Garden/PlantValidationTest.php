<?php

namespace App\Tests\Integration\Garden;

use App\Garden\Model\Planting;
use App\Garden\PlantingRepository;
use App\Garden\PlantValidation;
use App\Plant\Exception\PlantAlreadyValidatedException;
use App\Plant\Model\Plant;
use App\Tests\Factory\PlantFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class PlantValidationTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testADraftOfTheChatJoinsTheGarden(): void
    {
        self::mockTime('2026-11-21 10:00');
        $plant = $this->save(Plant::propose('Panicled hydrangea', 'Hydrangea paniculata', 'Hydrangeaceae', PlantFactory::careSheet(), new \DateTimeImmutable('2026-11-21 09:55'), 191052));

        $this->validate($plant);

        self::assertSame('2026-11-21 10:00', $plant->getValidatedAt()?->format('Y-m-d H:i'));
        $plantings = $this->plantingsOf($plant);
        self::assertCount(1, $plantings);
        self::assertSame('Panicled hydrangea', $plantings[0]->getName());
        self::assertSame('2026-11-21 10:00', $plantings[0]->getCreatedAt()->format('Y-m-d H:i'));
    }

    public function testTheDraftOfAPlantingKeepsItsPlanting(): void
    {
        $plant = $this->save(PlantFactory::draft());
        $planting = new Planting('Hydrangea by the shed', null, null, new \DateTimeImmutable('2026-11-01'));
        $planting->linkTo($plant);
        $this->entityManager->persist($planting);
        $this->entityManager->flush();

        $this->validate($plant);

        self::assertSame(['Hydrangea by the shed'], array_map(static fn (Planting $planting): string => $planting->getName(), $this->plantingsOf($plant)));
    }

    public function testAStaleDraftIsReadAgainBeforeItIsValidated(): void
    {
        $plant = $this->save(PlantFactory::draft());
        // Validated meanwhile by another request: this one still holds the draft
        $this->entityManager->getConnection()->executeStatement("UPDATE plant SET status = 'validated', validated_at = '2026-11-21 10:00:00' WHERE id = ?", [$plant->getId()]);
        self::assertFalse($plant->isValidated());

        try {
            $this->validate($plant);
            self::fail('Validated twice.');
        } catch (PlantAlreadyValidatedException) {
        }

        self::assertSame('2026-11-21 10:00', $plant->getValidatedAt()?->format('Y-m-d H:i'), 'The validation of the other request.');
        self::assertSame([], $this->plantingsOf($plant), 'The other request plants it.');
    }

    private function validate(Plant $plant): void
    {
        $this->entityManager->getConnection()->transactional(function () use ($plant): void {
            self::getContainer()->get(PlantValidation::class)->validate($plant);
            $this->entityManager->flush();
        });
    }

    private function save(Plant $plant): Plant
    {
        $this->entityManager->persist($plant);
        $this->entityManager->flush();

        return $plant;
    }

    /**
     * @return list<Planting>
     */
    private function plantingsOf(Plant $plant): array
    {
        return array_values(self::getContainer()->get(PlantingRepository::class)->findBy(['plant' => $plant->getId()]));
    }
}

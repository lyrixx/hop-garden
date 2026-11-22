<?php

namespace App\Tests\Unit\Garden\Form;

use App\Garden\Form\Model\GardenFormData;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Validation;

final class GardenFormDataTest extends TestCase
{
    public function testACityNotFound(): void
    {
        $data = GardenFormData::forNewGarden();
        $data->name = 'Balcony';
        $data->city = 'Atlantis';

        self::assertSame([['garden.place.not_found', ['{{ city }}' => 'Atlantis']]], self::violations($data));
    }

    public function testInDemoModeACityNotFoundGetsTheOnesARecordingAnswers(): void
    {
        $data = GardenFormData::forNewGarden();
        $data->name = 'Balcony';
        $data->city = 'Atlantis';
        $data->demoPlaceQueries = ['Paris', 'Paron', '89100'];

        self::assertSame([
            ['garden.place.not_found', ['{{ city }}' => 'Atlantis']],
            ['garden.place.not_found_in_demo', ['{{ places }}' => 'Paris, Paron, 89100']],
        ], self::violations($data));
    }

    /**
     * @return list<array{string, array<string, mixed>}> the message and the parameters of each violation of a search
     */
    private static function violations(GardenFormData $data): array
    {
        $violations = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator()->validate($data, null, [GardenFormData::SEARCH]);

        return array_map(static fn (ConstraintViolationInterface $violation): array => [$violation->getMessageTemplate(), $violation->getParameters()], iterator_to_array($violations, false));
    }
}

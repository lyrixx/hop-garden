<?php

namespace App\Tests\Unit\DataSource\OpenMeteo\Model;

use App\DataSource\OpenMeteo\Model\PlaceQuery;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class PlaceQueryTest extends TestCase
{
    #[TestWith(['Paron', 'Paron', null])]
    #[TestWith(['Saint-Denis-lès-Sens', 'Saint-Denis-lès-Sens', null])]
    // What follows a comma: a country or a region, for Open-Meteo
    #[TestWith(['Paron, France', 'Paron, France', null])]
    #[TestWith(['89100', null, '89100'])]
    #[TestWith(['89100 Paron', 'Paron', '89100'])]
    #[TestWith(['Paron 89100', 'Paron', '89100'])]
    #[TestWith(['  Paron   89100 ', 'Paron', '89100'])]
    #[TestWith(['Paron (89100)', 'Paron', '89100'])]
    #[TestWith(['Paron, 89100', 'Paron', '89100'])]
    #[TestWith(['89100 Paron, France', 'Paron, France', '89100'])]
    #[TestWith(['Saint Martin du Tertre 89100', 'Saint Martin du Tertre', '89100'])]
    #[TestWith(['London SW1A 1AA', 'London', 'SW1A 1AA'])]
    // A word with a digit, whatever it is: GeocodingClient searches "Paris 17" as typed first
    #[TestWith(['Paris 17', 'Paris', '17'])]
    #[TestWith([' , ', null, null])]
    public function testThePostcodeIsSplitFromTheName(string $query, ?string $name, ?string $postcode): void
    {
        $parsed = PlaceQuery::parse($query);

        self::assertSame([$name, $postcode], [$parsed->name, $parsed->postcode]);
    }
}

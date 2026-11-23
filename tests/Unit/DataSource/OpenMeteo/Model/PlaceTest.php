<?php

namespace App\Tests\Unit\DataSource\OpenMeteo\Model;

use App\DataSource\OpenMeteo\Model\Place;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class PlaceTest extends TestCase
{
    public function testASuggestionShowsItsOnlyPostcodeOrTheOneItWasFoundBy(): void
    {
        $paron = self::place('Paron', 'Bourgogne-Franche-Comté', 'France', ['89100']);
        $sens = self::place('Sens', 'Bourgogne-Franche-Comté', 'France', ['89100', '89090 CEDEX', '89091 CEDEX']);
        $auxerre = self::place('Auxerre', 'Bourgogne-Franche-Comté', 'France', ['89000', '89290', '89001 CEDEX']);

        self::assertSame('Paron (89100), Bourgogne-Franche-Comté, France', $paron->suggestion());
        self::assertSame('Sens (89100), Bourgogne-Franche-Comté, France', $sens->suggestion(), 'A CEDEX aside.');
        self::assertSame('Auxerre, Bourgogne-Franche-Comté, France', $auxerre->suggestion(), 'Of several: none.');
        self::assertSame('Auxerre (89290), Bourgogne-Franche-Comté, France', $auxerre->foundByPostcode('89290')->suggestion());
        self::assertSame('London, England, United Kingdom', self::place('London', 'England', 'United Kingdom', [])->suggestion());
        self::assertSame('Singapore (018989)', self::place('Singapore', 'Singapore', 'Singapore', ['018989'])->suggestion(), 'A region named as its city once only.');
        self::assertSame('Paron, Bourgogne-Franche-Comté, France', $paron->foundByPostcode('89100')->label(), 'The name a garden keeps: without postcode.');
    }

    // A CEDEX of the place, then whatever the spaces
    #[TestWith(['89100', true])]
    #[TestWith(['89090', true])]
    #[TestWith(['89 100', true])]
    #[TestWith(['8910', false])]
    #[TestWith(['89000', false])]
    public function testItsPostcodes(string $postcode, bool $has): void
    {
        self::assertSame($has, self::place('Sens', null, 'France', ['89100', '89090 CEDEX'])->hasPostcode($postcode));
    }

    // As Open-Meteo: its beginning, whatever the case, the accents and the hyphens
    #[TestWith(['Paron', 'Paron', true])]
    #[TestWith(['Paron', 'paron', true])]
    #[TestWith(['Paron', 'Par', true])]
    #[TestWith(['Saint-Denis-lès-Sens', 'saint denis les', true])]
    #[TestWith(['Saint-Denis-lès-Sens', 'Sens', false])]
    #[TestWith(['Paron', '(', false])]
    public function testItsName(string $placeName, string $name, bool $named): void
    {
        self::assertSame($named, self::place($placeName, null, 'France', [])->isNamed($name));
    }

    /**
     * @param list<string> $postcodes
     */
    private static function place(string $name, ?string $region, ?string $country, array $postcodes): Place
    {
        return new Place(1, $name, $region, $country, 48.0, 3.0, 'Europe/Paris', $postcodes);
    }
}

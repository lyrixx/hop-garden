<?php

namespace App\Tests\Unit\Garden;

use App\Garden\CareSheetDraftPrompt;
use App\Garden\Model\Planting;
use PHPUnit\Framework\TestCase;

final class CareSheetDraftPromptTest extends TestCase
{
    public function testTheSpeciesTheUserChoseIsGiven(): void
    {
        $prompt = CareSheetDraftPrompt::for(self::planting('Hortensia du portail', 191052, 'Hydrangea paniculata'));

        self::assertStringStartsWith('Draft the care sheet of a plant of my garden, which I call "Hortensia du portail". I chose its species: Hydrangea paniculata (Trefle id 191052).', $prompt);
        self::assertStringEndsWith('ask no question, and finish with hopgarden_propose_plant for this species.', $prompt);
        self::assertStringNotContainsString('hopgarden_search_plant', $prompt);
    }

    public function testASpeciesWithoutTrefleId(): void
    {
        self::assertStringContainsString('which I call "Hortensia". I chose its species: Hydrangea paniculata. This runs', CareSheetDraftPrompt::for(self::planting('Hortensia', null, 'Hydrangea paniculata')));
    }

    public function testAnUnknownSpeciesIsIdentifiedFirst(): void
    {
        $prompt = CareSheetDraftPrompt::for(self::planting('The "mystery" shrub', null, null));

        self::assertStringStartsWith('Draft the care sheet of a plant of my garden, which I call "The \'mystery\' shrub". I do not know its species.', $prompt, 'The quotes of the name do not end the quoted name.');
        self::assertStringContainsString('Identify it with hopgarden_search_plant', $prompt);
        self::assertStringEndsWith('then finish with hopgarden_propose_plant.', $prompt);
    }

    private static function planting(string $name, ?int $trefleId, ?string $scientificName): Planting
    {
        return new Planting($name, null, null, new \DateTimeImmutable('2026-11-21'), $trefleId, $scientificName);
    }
}

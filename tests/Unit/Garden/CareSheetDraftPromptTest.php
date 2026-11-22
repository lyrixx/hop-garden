<?php

namespace App\Tests\Unit\Garden;

use App\Factory\PlantingFactory;
use App\Garden\CareSheetDraftPrompt;
use PHPUnit\Framework\TestCase;

final class CareSheetDraftPromptTest extends TestCase
{
    public function testTheSpeciesTheUserChoseIsGiven(): void
    {
        $prompt = CareSheetDraftPrompt::for(PlantingFactory::createOne(['name' => 'Hortensia du portail', 'trefleId' => 191052, 'scientificName' => 'Hydrangea paniculata']));

        self::assertStringStartsWith('Draft the care sheet of a plant of my garden, which I call "Hortensia du portail". I chose its species: Hydrangea paniculata (Trefle id 191052).', $prompt);
        self::assertStringEndsWith('ask no question. The plant is in my garden already: propose no space nor planting, and finish with hopgarden_propose_plant for this species.', $prompt);
        self::assertStringNotContainsString('hopgarden_search_plant', $prompt);
    }

    public function testASpeciesWithoutTrefleId(): void
    {
        self::assertStringContainsString('which I call "Hortensia". I chose its species: Hydrangea paniculata. This runs', CareSheetDraftPrompt::for(PlantingFactory::createOne(['name' => 'Hortensia', 'scientificName' => 'Hydrangea paniculata'])));
    }

    public function testAnUnknownSpeciesIsIdentifiedFirst(): void
    {
        $prompt = CareSheetDraftPrompt::for(PlantingFactory::createOne(['name' => 'The "mystery" shrub']));

        self::assertStringStartsWith('Draft the care sheet of a plant of my garden, which I call "The \'mystery\' shrub". I do not know its species.', $prompt, 'The quotes of the name do not end the quoted name.');
        self::assertStringContainsString('Identify it with hopgarden_search_plant', $prompt);
        self::assertStringContainsString('propose no space nor planting', $prompt);
        self::assertStringEndsWith('then finish with hopgarden_propose_plant.', $prompt);
    }
}

<?php

namespace App\Mcp\Tool;

use App\DataSource\Exception\DataSourceUnavailableException;
use App\DataSource\Trefle\Model\PlantCandidate;
use App\DataSource\Trefle\TrefleClient;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

#[McpTool(
    name: 'search_plant',
    title: 'Search a plant',
    description: <<<'TXT'
        Identify a plant from a common or scientific name, in any language ("hortensia", "Hydrangea paniculata"), with the Trefle botanical database.
        Returns the matching species: Trefle id, scientific name, English common name, family and image URL.
        Several credible candidates mean the name is ambiguous: ask the user which one they have before going further.
        An empty list means Trefle does not know the name: rely on your own knowledge, with a low confidence.
        TXT,
    annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: true),
    outputSchema: [
        'type' => 'object',
        'properties' => [
            'candidates' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'trefle_id' => ['type' => 'integer'],
                        'scientific_name' => ['type' => 'string'],
                        'common_name' => ['type' => ['string', 'null']],
                        'family' => ['type' => ['string', 'null']],
                        'image_url' => ['type' => ['string', 'null']],
                    ],
                    'required' => ['trefle_id', 'scientific_name', 'common_name', 'family', 'image_url'],
                ],
            ],
        ],
        'required' => ['candidates'],
    ],
)]
final readonly class SearchPlantTool
{
    public function __construct(
        private TrefleClient $trefleClient,
    ) {
    }

    /**
     * @return array{candidates: list<array<string, mixed>>}
     */
    public function __invoke(
        #[Schema(description: 'Plant name as the user wrote it, common or scientific. Example: "hortensia".', minLength: 2, maxLength: 100)]
        string $query,
    ): array {
        try {
            $candidates = $this->trefleClient->search($query);
        } catch (DataSourceUnavailableException $e) {
            throw new ToolCallException($e->getMessage() . ' Continue with your own knowledge and give a low confidence to every value.');
        }

        return [
            'candidates' => array_map(static fn (PlantCandidate $candidate): array => [
                'trefle_id' => $candidate->trefleId,
                'scientific_name' => $candidate->scientificName,
                'common_name' => $candidate->commonName,
                'family' => $candidate->family,
                'image_url' => $candidate->imageUrl,
            ], $candidates),
        ];
    }
}

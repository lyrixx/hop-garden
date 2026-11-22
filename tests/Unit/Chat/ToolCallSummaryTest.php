<?php

namespace App\Tests\Unit\Chat;

use App\Chat\ToolCallSummary;
use App\Chat\ToolName;
use PHPUnit\Framework\TestCase;

final class ToolCallSummaryTest extends TestCase
{
    public function testOneLineWithTheMcpNameOfTheTool(): void
    {
        self::assertSame(
            'propose_plant(common_name: "Panicled hydrangea", trefle_id: 191052, frost_sensitive: false, image_url: null, care_sheet: {…}, months: [2 items])',
            ToolCallSummary::of('hopgarden_propose_plant', [
                'common_name' => 'Panicled hydrangea',
                'trefle_id' => 191052,
                'frost_sensitive' => false,
                'image_url' => null,
                'care_sheet' => ['min_temperature_c' => -25],
                'months' => [2, 3],
            ]),
        );
    }

    public function testAToolWithoutArguments(): void
    {
        self::assertSame('list_collection()', ToolCallSummary::of('hopgarden_list_collection', []));
    }

    public function testLongValuesAreCutAfter40Characters(): void
    {
        self::assertSame(
            'search_plant(query: "Hortènsia, à côté du portail / près de …, url: "https://trefle.io/api")',
            ToolCallSummary::of('hopgarden_search_plant', ['query' => 'Hortènsia, à côté du portail / près de la haie', 'url' => 'https://trefle.io/api']),
        );
    }

    public function testTheMcpNameDropsThePrefixOfTheServerOnly(): void
    {
        self::assertSame('search_plant', ToolName::toMcp('hopgarden_search_plant'));
        self::assertSame('search_plant', ToolName::toMcp('search_plant'), 'Already an MCP name.');
        self::assertSame('other_hopgarden_tool', ToolName::toMcp('other_hopgarden_tool'), 'Only a leading prefix.');
    }
}

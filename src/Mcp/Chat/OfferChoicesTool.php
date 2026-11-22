<?php

namespace App\Mcp\Chat;

use App\Chat\Model\Choices;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * Only on the server of the chat agent (/_mcp/agent): the buttons show in the chat, see App\Chat\AgentRunner.
 */
#[McpTool(
    name: 'offer_choices',
    title: 'Offer choices to tap',
    description: <<<'TXT'
        Show the user a question with one button per option, under your message: they answer by tapping one, or by typing.
        Use it whenever the answer is one of a few discrete options: which species among the candidates, which space, which exposure, which soil.
        It changes nothing: the answer comes as the next message of the user. After calling it, stop: do not write the question nor the options again.
        TXT,
    annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    outputSchema: [
        'type' => 'object',
        'properties' => [
            'question' => ['type' => 'string'],
            'options' => ['type' => 'array', 'items' => ['type' => 'string']],
            'next_step' => ['type' => 'string'],
        ],
        'required' => ['question', 'options', 'next_step'],
    ],
)]
final readonly class OfferChoicesTool
{
    /**
     * @param list<string> $options
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        #[Schema(description: 'The question, short, in the language of the user. Example: "Which hydrangea do you have?".', minLength: 1, maxLength: 200)]
        string $question,
        #[Schema(
            description: 'The options, each a few words in the language of the user: a tap sends its text as the answer of the user. Example: ["Bigleaf hydrangea (H. macrophylla)", "Panicled hydrangea (H. paniculata)"].',
            items: ['type' => 'string', 'minLength' => 1, 'maxLength' => 80],
            minItems: 2,
            maxItems: 6,
            uniqueItems: true,
        )]
        array $options,
    ): array {
        return (new Choices(trim($question), array_map(trim(...), $options)))->toArray() + [
            'next_step' => 'The question and its buttons are shown to the user. Stop here: end your turn in a few words at most, without repeating the question or its options, and wait for their answer.',
        ];
    }
}

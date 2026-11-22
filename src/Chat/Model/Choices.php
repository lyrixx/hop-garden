<?php

namespace App\Chat\Model;

/**
 * A question of the agent with its options, as buttons under its message: a tap sends the option as the user's next
 * message, as if typed.
 */
final readonly class Choices
{
    /**
     * @param list<string> $options
     */
    public function __construct(
        public string $question,
        public array $options,
    ) {
    }

    /**
     * @param array<mixed> $data the result of offer_choices, read again from the history of the chat
     *
     * @return ?self null for a malformed result
     */
    public static function fromArray(array $data): ?self
    {
        $question = $data['question'] ?? null;
        $options = $data['options'] ?? null;

        if (!\is_string($question) || !\is_array($options) || [] === $options) {
            return null;
        }

        $texts = array_values(array_filter($options, \is_string(...)));

        return \count($texts) === \count($options) ? new self($question, $texts) : null;
    }

    /**
     * @return array{question: string, options: list<string>}
     */
    public function toArray(): array
    {
        return ['question' => $this->question, 'options' => $this->options];
    }
}

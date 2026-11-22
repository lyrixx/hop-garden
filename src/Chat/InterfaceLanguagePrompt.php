<?php

namespace App\Chat;

use Symfony\AI\Agent\Attribute\AsInputProcessor;
use Symfony\AI\Agent\Input;
use Symfony\AI\Agent\InputProcessorInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Ends the prompt of the gardener (config/agent/gardener.md) with the language of the interface: the one the handler
 * switched to, from its message. The agent answers in it when a message does not tell (a tap on a choice or a card),
 * and writes in it what shows in the interface. At each call, never stored: the conversation keeps no system message.
 * After the system prompt (-30).
 */
#[AsInputProcessor(agent: 'ai.agent.gardener', priority: -35)]
final readonly class InterfaceLanguagePrompt implements InputProcessorInterface
{
    public function __construct(
        private LocaleSwitcher $localeSwitcher,
        private TranslatorInterface $translator,
    ) {
    }

    public function processInput(Input $input): void
    {
        $messages = $input->getMessageBag();
        $prompt = $messages->getSystemMessage()?->getContent();
        if (!\is_string($prompt)) {
            throw new \LogicException('The gardener needs its prompt, as text, before its language: see config/packages/ai.yaml.');
        }

        $input->setMessageBag($messages->withSystemMessage(Message::forSystem($prompt . "\n\n" . $this->section())));
    }

    private function section(): string
    {
        $locale = $this->localeSwitcher->getLocale();
        $language = \Locale::getDisplayLanguage($locale, 'en');
        $buttons = implode(', ', array_map(
            fn (string $key): string => '"' . $this->translator->trans($key, locale: $locale) . '"',
            ['action.validate', 'action.create_the_space', 'action.add_to_my_garden'],
        ));

        return <<<PROMPT
            ## The language of the interface

            The interface of Hop Garden is in {$language} ({$locale}). Reply in {$language} by default: to a message that
            does not tell its language (a tap on a choice or a card, a Latin name), and to the messages a tap on a card
            sends in English. Reply in another language only when the user writes in it.

            Whatever the language of the conversation, write in {$language} what shows in the interface: the common name,
            the pruning and cutting advice and the notes of a care sheet you propose. The Latin name stays the pivot.

            The buttons of the cards read {$buttons}.
            PROMPT;
    }
}

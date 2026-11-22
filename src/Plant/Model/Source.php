<?php

namespace App\Plant\Model;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class Source implements TranslatableInterface
{
    public const string LLM_KNOWLEDGE = 'LLM knowledge';
    public const string TREFLE = 'Trefle';

    public function __construct(
        public string $name,
        public ?string $url = null,
    ) {
    }

    public function isLlmKnowledge(): bool
    {
        return self::LLM_KNOWLEDGE === $this->name;
    }

    /**
     * The name is stored as the agent gave it: only "LLM knowledge" is ours to translate, Trefle and the others are
     * names.
     */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $this->isLlmKnowledge() ? $translator->trans('source.llm_knowledge', locale: $locale) : $this->name;
    }
}

<?php

namespace App\Plant\Model;

final readonly class Source
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
}

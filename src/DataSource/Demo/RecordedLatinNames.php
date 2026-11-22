<?php

namespace App\DataSource\Demo;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * In demo mode, the Latin names of a vernacular name come from fixtures/llm/latin-names.json instead of an LLM: see
 * App\Garden\LatinNameResolver.
 */
final readonly class RecordedLatinNames
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/fixtures/llm/latin-names.json')]
        private string $file,
    ) {
    }

    /**
     * @return list<string> none for a name the file does not record
     */
    public function of(string $name): array
    {
        /** @var array<string, list<string>> $latinNames */
        $latinNames = json_decode((string) file_get_contents($this->file), true, flags: JSON_THROW_ON_ERROR);

        return $latinNames[(new AsciiSlugger())->slug($name)->lower()->toString()] ?? [];
    }
}

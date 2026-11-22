<?php

namespace App\Plant\Entity;

use App\Plant\Exception\InvalidCareSheetException;
use App\Plant\Model\CareSheetField;
use App\Plant\Model\Confidence;
use App\Plant\Model\Exposure;
use App\Plant\Model\Source;
use App\Plant\Model\Watering;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
final class CareSheet
{
    /** @var list<int> */
    #[ORM\Column(type: Types::JSON)]
    private array $pruningMonths;

    /** @var list<int> */
    #[ORM\Column(type: Types::JSON)]
    private array $deadheadingMonths;

    /** @var list<int> */
    #[ORM\Column(type: Types::JSON)]
    private array $cuttingMonths;

    /** @var array<string, string> field name => confidence */
    #[ORM\Column(type: Types::JSON)]
    private array $confidence;

    /** @var list<array{name: string, url: ?string}> */
    #[ORM\Column(type: Types::JSON)]
    private array $sources;

    /**
     * @param list<int>                 $pruningMonths
     * @param list<int>                 $deadheadingMonths
     * @param list<int>                 $cuttingMonths
     * @param array<string, Confidence> $confidence        keyed by CareSheetField values
     * @param list<Source>              $sources
     */
    public function __construct(
        #[ORM\Column]
        private int $minTemperatureC,
        #[ORM\Column]
        private bool $frostSensitive,
        #[ORM\Column]
        private Exposure $exposure,
        #[ORM\Column]
        private Watering $watering,
        array $pruningMonths,
        array $deadheadingMonths,
        array $cuttingMonths,
        #[ORM\Column(type: Types::TEXT)]
        private string $pruningAdvice,
        #[ORM\Column(type: Types::TEXT)]
        private string $cuttingAdvice,
        array $confidence,
        array $sources,
    ) {
        if ($minTemperatureC < -60 || $minTemperatureC > 30) {
            throw new InvalidCareSheetException(\sprintf('The minimum temperature must be between -60 and 30 °C, got %d.', $minTemperatureC));
        }

        $this->pruningMonths = self::normalizeMonths($pruningMonths);
        $this->deadheadingMonths = self::normalizeMonths($deadheadingMonths);
        $this->cuttingMonths = self::normalizeMonths($cuttingMonths);

        $this->confidence = [];
        foreach ($confidence as $field => $level) {
            if (null === CareSheetField::tryFrom($field)) {
                throw new InvalidCareSheetException(\sprintf('Unknown care sheet field "%s" in the confidence map.', $field));
            }
            $this->confidence[$field] = $level->value;
        }

        $this->sources = array_map(
            static fn (Source $source): array => ['name' => $source->name, 'url' => $source->url],
            $sources,
        );
    }

    /**
     * A care sheet to fill in by hand: every value is a placeholder, of low confidence, so that the card highlights it
     * until the user changes it.
     *
     * @param list<Source> $sources
     */
    public static function blank(array $sources): self
    {
        return new self(0, false, Exposure::Sun, Watering::Medium, [], [], [], '', '', array_fill_keys(CareSheetField::values(), Confidence::Low), $sources);
    }

    public function getMinTemperatureC(): int
    {
        return $this->minTemperatureC;
    }

    public function isFrostSensitive(): bool
    {
        return $this->frostSensitive;
    }

    public function getExposure(): Exposure
    {
        return $this->exposure;
    }

    public function getWatering(): Watering
    {
        return $this->watering;
    }

    /**
     * @return list<int>
     */
    public function getPruningMonths(): array
    {
        return $this->pruningMonths;
    }

    /**
     * @return list<int>
     */
    public function getDeadheadingMonths(): array
    {
        return $this->deadheadingMonths;
    }

    /**
     * @return list<int>
     */
    public function getCuttingMonths(): array
    {
        return $this->cuttingMonths;
    }

    public function getPruningAdvice(): string
    {
        return $this->pruningAdvice;
    }

    public function getCuttingAdvice(): string
    {
        return $this->cuttingAdvice;
    }

    /**
     * A field the agent gave no confidence for is treated as uncertain.
     */
    public function getConfidenceOf(CareSheetField $field): Confidence
    {
        return Confidence::tryFrom($this->confidence[$field->value] ?? '') ?? Confidence::Low;
    }

    /**
     * @return array<string, Confidence>
     */
    public function getConfidences(): array
    {
        $confidences = [];
        foreach (CareSheetField::cases() as $field) {
            $confidences[$field->value] = $this->getConfidenceOf($field);
        }

        return $confidences;
    }

    /**
     * @return list<Source>
     */
    public function getSources(): array
    {
        return array_map(
            static fn (array $source): Source => new Source($source['name'], $source['url']),
            $this->sources,
        );
    }

    /**
     * Trefle does not back this sheet: the LLM filled it alone.
     */
    public function isLlmOnly(): bool
    {
        foreach ($this->getSources() as $source) {
            if (!$source->isLlmKnowledge()) {
                return false;
            }
        }

        return true;
    }

    /**
     * The assistant filled the values: every sheet of propose_plant lists "LLM knowledge" (CareSheetInput). A sheet
     * created by hand never does, the user's edits add "You" only.
     */
    public function hasLlmKnowledge(): bool
    {
        foreach ($this->getSources() as $source) {
            if ($source->isLlmKnowledge()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<int> $months
     *
     * @return list<int>
     */
    private static function normalizeMonths(array $months): array
    {
        foreach ($months as $month) {
            if ($month < 1 || $month > 12) {
                throw new InvalidCareSheetException(\sprintf('A month must be between 1 and 12, got %d.', $month));
            }
        }

        $months = array_values(array_unique($months));
        sort($months);

        return $months;
    }
}

<?php

namespace App\Twig\Components;

use App\Plant\Model\CareSheet;
use App\Reminder\FrostRiskEvaluator;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The scale of the plate: the temperatures, from the cold the plant bears to the frost alert threshold, up to 10 °C.
 */
#[AsTwigComponent]
final class HardinessScale
{
    private const int HIGH_C = 10;
    private const int TICK_STEP_C = 5;

    public CareSheet $careSheet;

    public function __construct(
        private readonly FrostRiskEvaluator $frostRiskEvaluator,
    ) {
    }

    public function getColdest(): int
    {
        return $this->careSheet->getMinTemperatureC();
    }

    public function getThreshold(): float
    {
        return $this->frostRiskEvaluator->thresholdOf($this->careSheet);
    }

    /**
     * @return list<int> every 5 °C, from a round ten below the coldest (−30 °C at least) to 10 °C
     */
    public function getTicks(): array
    {
        return range($this->low(), self::HIGH_C, self::TICK_STEP_C);
    }

    /**
     * Whether a tick shows its temperature: every 10 °C, unless the label of the threshold stands there.
     */
    public function isLabelled(int $degrees): bool
    {
        return 0 === $degrees % 10 && abs($degrees - $this->getThreshold()) >= 4;
    }

    /**
     * @return float in percent of the width of the scale
     */
    public function position(float $degrees): float
    {
        $low = $this->low();

        return ($degrees - $low) / (self::HIGH_C - $low) * 100;
    }

    private function low(): int
    {
        return min(-30, (int) floor(($this->getColdest() - 5) / 10) * 10);
    }
}

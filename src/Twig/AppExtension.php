<?php

namespace App\Twig;

use App\Chat\ChatMarkdown;
use App\Plant\CareSheetPresenter;
use App\Plant\Entity\CareSheet;
use App\Plant\Model\CareSheetRow;
use App\Reminder\FrostRiskEvaluator;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

final readonly class AppExtension
{
    public function __construct(
        private CareSheetPresenter $careSheetPresenter,
        private FrostRiskEvaluator $frostRiskEvaluator,
    ) {
    }

    /**
     * @return list<CareSheetRow>
     */
    #[AsTwigFunction('care_sheet_rows')]
    public function careSheetRows(CareSheet $careSheet): array
    {
        return $this->careSheetPresenter->rows($careSheet);
    }

    #[AsTwigFunction('frost_threshold')]
    public function frostThreshold(CareSheet $careSheet): float
    {
        return $this->frostRiskEvaluator->thresholdOf($careSheet);
    }

    #[AsTwigFunction('month_name')]
    public function monthName(int $month): string
    {
        return CareSheetPresenter::monthName($month);
    }

    #[AsTwigFilter('chat_markdown', isSafe: ['html'])]
    public function chatMarkdown(string $text): string
    {
        return ChatMarkdown::toHtml($text);
    }
}

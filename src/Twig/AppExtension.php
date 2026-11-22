<?php

namespace App\Twig;

use App\Chat\ChatMarkdown;
use App\Plant\CareSheetPresenter;
use App\Plant\Entity\CareSheet;
use App\Plant\Model\CareSheetRow;
use App\Reminder\FrostRiskEvaluator;
use Symfony\Component\Uid\Uuid;
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

    /**
     * The number of a specimen, of a plate: the end of its id, as a herbarium labels its sheets ("No. 9F28").
     */
    #[AsTwigFilter('specimen_number')]
    public function specimenNumber(Uuid $id): string
    {
        return strtoupper(substr($id->toRfc4122(), -4));
    }

    #[AsTwigFilter('chat_markdown', isSafe: ['html'])]
    public function chatMarkdown(string $text): string
    {
        return ChatMarkdown::toHtml($text);
    }
}

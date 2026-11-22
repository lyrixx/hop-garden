<?php

namespace App\Garden\Exception;

final class CareSheetAlreadyLinkedException extends \RuntimeException
{
    public static function forPlanting(int $id): self
    {
        return new self(\sprintf('Planting %d already has its care sheet.', $id));
    }
}

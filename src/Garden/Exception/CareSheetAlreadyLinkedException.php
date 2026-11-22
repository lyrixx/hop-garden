<?php

namespace App\Garden\Exception;

use Symfony\Component\Uid\Uuid;

final class CareSheetAlreadyLinkedException extends \RuntimeException
{
    public static function forPlanting(Uuid $id): self
    {
        return new self(\sprintf('Planting %s already has its care sheet.', $id));
    }
}

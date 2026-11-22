<?php

namespace App\Plant\Model;

final readonly class CareSheetRow
{
    public function __construct(
        public CareSheetField $field,
        public string $value,
        public Confidence $confidence,
    ) {
    }
}

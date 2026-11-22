<?php

namespace App\Plant\Model;

enum PlantStatus: string
{
    case Draft = 'draft';
    case Validated = 'validated';
}

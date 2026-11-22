<?php

namespace App\Garden\Exception;

/**
 * A garden spent what it may ask the LLM today (App\Garden\GardenQuotas).
 */
final class QuotaReachedException extends \RuntimeException
{
    public static function forLatinNames(): self
    {
        return new self('The garden asked the LLM for the Latin names of its species search too often today.');
    }
}

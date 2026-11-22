<?php

namespace App\Garden\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * The species picked in a SpeciesPickerData is one Trefle finds for its name: the browser sends the id only, and
 * the rest comes from Trefle again.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class PickedSpecies extends Constraint
{
    public string $notFoundMessage = 'species.picked.not_found';
    public string $unavailableMessage = 'species.picked.unavailable';

    /**
     * @param list<string>|null $groups
     */
    public function __construct(?array $groups = null, mixed $payload = null)
    {
        parent::__construct(null, $groups, $payload);
    }

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}

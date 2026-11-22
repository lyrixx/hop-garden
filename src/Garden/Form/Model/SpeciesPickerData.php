<?php

namespace App\Garden\Form\Model;

/**
 * The data of a form with a species picker (App\Twig\Components\SpeciesPicker): the name as typed, and the species
 * picked among the ones Trefle finds for it. The form has the three fields, named as these properties.
 */
interface SpeciesPickerData
{
    public string $name { get; }

    public ?int $trefleId { get; }

    public ?string $scientificName { get; }
}

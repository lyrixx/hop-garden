<?php

namespace App\Garden\Form\Model;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The name of a plant added from its "Add to my garden" card: its species and its space are those of the card.
 */
final class PlantingProposalFormData
{
    public function __construct(
        // Keep in sync with App\Garden\Form\Model\PlantingFormData::$name
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 100)]
        public string $name = '',
    ) {
    }
}

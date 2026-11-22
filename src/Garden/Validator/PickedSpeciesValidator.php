<?php

namespace App\Garden\Validator;

use App\Garden\Form\Model\SpeciesPickerData;
use App\Garden\SpeciesSearch;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class PickedSpeciesValidator extends ConstraintValidator
{
    public function __construct(
        private readonly SpeciesSearch $speciesSearch,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof PickedSpecies) {
            throw new UnexpectedTypeException($constraint, PickedSpecies::class);
        }
        if (!$value instanceof SpeciesPickerData) {
            throw new UnexpectedValueException($value, SpeciesPickerData::class);
        }

        // No species picked: for the constraints of the field to say
        if (null === $value->trefleId) {
            return;
        }

        $result = $this->speciesSearch->search($value->name);
        if (null !== $result && $result->trefleUnavailable) {
            $this->context->buildViolation($constraint->unavailableMessage)->addViolation();
        } elseif (null === $result?->find($value->trefleId)) {
            $this->context->buildViolation($constraint->notFoundMessage)->addViolation();
        }
    }
}

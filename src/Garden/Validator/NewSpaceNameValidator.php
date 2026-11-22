<?php

namespace App\Garden\Validator;

use App\Garden\CurrentGarden;
use App\Garden\SpaceRepository;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class NewSpaceNameValidator extends ConstraintValidator
{
    public function __construct(
        private readonly SpaceRepository $spaceRepository,
        private readonly CurrentGarden $currentGarden,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof NewSpaceName) {
            throw new UnexpectedTypeException($constraint, NewSpaceName::class);
        }

        // A blank name is for NotBlank to say
        if (!\is_string($value) || '' === trim($value)) {
            return;
        }

        $existing = $this->spaceRepository->findOneByName($this->currentGarden->get(), trim($value));
        if (null !== $existing) {
            $this->context->buildViolation($constraint->message)->setParameter('{{ name }}', $existing->getName())->addViolation();
        }
    }
}

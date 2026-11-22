<?php

namespace App\Account\Command;

use App\Account\Entity\User;
use App\Account\UserRepository;
use App\Account\Validator\NewPassword;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Without prompt: "castor app:user:create" creates the administrator of a new installation with it.
 */
#[AsCommand(name: 'app:user:create', description: 'Creates an account, its email verified, e.g. the administrator: --admin')]
final readonly class CreateUserCommand
{
    public function __construct(
        private UserRepository $userRepository,
        private EntityManagerInterface $entityManager,
        private PasswordHasherFactoryInterface $passwordHasherFactory,
        private ValidatorInterface $validator,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Their email, to log in')]
        string $email = '',
        #[Option(description: 'The name Hop Garden shows')]
        string $name = '',
        #[Option(description: 'Their password, of 12 characters at least')]
        string $password = '',
        #[Option(description: 'Approved: they log in at once, instead of waiting on the list')]
        bool $approved = false,
        #[Option(description: 'The administrator, of the back-office (/admin): approved too')]
        bool $admin = false,
    ): int {
        $errors = [
            ...$this->violations('--email', $email, [new Assert\NotBlank(), new Assert\Email()]),
            ...$this->violations('--name', $name, [new Assert\NotBlank(normalizer: 'trim'), new Assert\Length(max: 100)]),
            ...$this->violations('--password', $password, [new NewPassword()]),
        ];
        if ([] === $errors && null !== $this->userRepository->findOneByEmail($email)) {
            $errors[] = \sprintf('An account has the email "%s" already.', User::normalizeEmail($email));
        }
        if ([] !== $errors) {
            $io->error($errors);

            return Command::INVALID;
        }

        $now = $this->clock->now();
        $user = User::register($email, trim($name), $this->passwordHasherFactory->getPasswordHasher(User::class)->hash($password), null, $now);
        // Whoever runs the command vouches for the address
        $user->verifyEmail($now);
        if ($approved || $admin) {
            $user->approve($now);
        }
        if ($admin) {
            $user->grantAdmin();
        }

        $this->userRepository->add($user);
        $this->entityManager->flush();

        $io->success(\sprintf('%s <%s> has an account: %s.', $user->getDisplayName(), $user->getEmail(), match (true) {
            $admin => 'the administrator, approved',
            $approved => 'approved',
            default => 'on the waiting list',
        }));

        return Command::SUCCESS;
    }

    /**
     * @param list<Constraint> $constraints
     *
     * @return list<string>
     */
    private function violations(string $option, string $value, array $constraints): array
    {
        $messages = [];
        foreach ($this->validator->validate($value, $constraints) as $violation) {
            $messages[] = \sprintf('%s: %s', $option, $violation->getMessage());
        }

        return $messages;
    }
}

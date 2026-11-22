<?php

namespace App\Account;

use App\Account\Entity\PasswordResetRequest;
use App\Account\Entity\User;
use Psr\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\String\ByteString;

/**
 * "I forgot my password": one request at a time per user, a new password ends them all. The caller flushes.
 */
final readonly class PasswordReset
{
    public function __construct(
        private PasswordResetRequestRepository $passwordResetRequestRepository,
        private PasswordHasherFactoryInterface $passwordHasherFactory,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return string the token of the link, which only the email carries
     */
    public function request(User $user): string
    {
        $token = ByteString::fromRandom(48)->toString();

        $this->passwordResetRequestRepository->removeAllOf($user);
        $this->passwordResetRequestRepository->add(new PasswordResetRequest($user, $token, $this->clock->now()));

        return $token;
    }

    /**
     * Null for a token unknown, used or expired.
     */
    public function find(#[\SensitiveParameter] string $token): ?PasswordResetRequest
    {
        $request = $this->passwordResetRequestRepository->findOneByToken($token);

        return null !== $request && !$request->isExpired($this->clock->now()) ? $request : null;
    }

    public function reset(PasswordResetRequest $request, #[\SensitiveParameter] string $plainPassword): void
    {
        $user = $request->getUser();
        $user->resetPassword($this->passwordHasherFactory->getPasswordHasher(User::class)->hash($plainPassword), $this->clock->now());

        $this->passwordResetRequestRepository->removeAllOf($user);
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class EmailVerificationService
{
    private const TOKEN_TTL = '+24 hours';

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UserRepository $userRepository,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(value: '%env(default:default_app_url:APP_URL)%')]
        private readonly string $appUrl,
    ) {
    }

    public function sendEmailChangeVerification(User $user, string $newEmail): void
    {
        $token = bin2hex(random_bytes(32));
        $user->setEmailVerificationToken($token);
        $user->setEmailVerificationTokenExpiresAt(new \DateTimeImmutable(self::TOKEN_TTL));
        $user->setUpdatedAt(new \DateTimeImmutable());
        $this->userRepository->getEntityManager()->flush();

        $verifyUrl = $this->appUrl . '/verify-email?token=' . $token . '&type=email&value=' . urlencode($newEmail);
        $body = "Bonjour,\n\nCliquez sur le lien suivant pour valider le changement d'adresse email :\n" . $verifyUrl . "\n\nCe lien expire dans 24 heures.";

        $email = (new Email())
            ->from(new Address('noreply@omtek.local', 'OMtek'))
            ->to($newEmail)
            ->subject('Vérification changement d\'email - OMtek')
            ->text($body);
        $this->mailer->send($email);
    }

    public function sendPasswordChangeVerification(User $user): void
    {
        $token = bin2hex(random_bytes(32));
        $user->setEmailVerificationToken($token);
        $user->setEmailVerificationTokenExpiresAt(new \DateTimeImmutable(self::TOKEN_TTL));
        $user->setUpdatedAt(new \DateTimeImmutable());
        $this->userRepository->getEntityManager()->flush();

        $verifyUrl = $this->appUrl . '/verify-email?token=' . $token . '&type=password';
        $body = "Bonjour,\n\nCliquez sur le lien suivant pour valider le changement de mot de passe :\n" . $verifyUrl . "\n\nCe lien expire dans 24 heures.";

        $email = (new Email())
            ->from(new Address('noreply@omtek.local', 'OMtek'))
            ->to($user->getEmail())
            ->subject('Vérification changement de mot de passe - OMtek')
            ->text($body);
        $this->mailer->send($email);
    }

    public function verifyEmailChange(string $token, string $newEmail): bool
    {
        $user = $this->userRepository->findOneBy(['emailVerificationToken' => $token]);
        if (!$user || !$this->isTokenValid($user)) {
            return false;
        }
        $user->setEmail($newEmail);
        $user->setEmailVerified(true);
        $this->clearToken($user);
        return true;
    }

    public function getUserByVerificationToken(string $token): ?User
    {
        $user = $this->userRepository->findOneBy(['emailVerificationToken' => $token]);
        if (!$user || !$this->isTokenValid($user)) {
            return null;
        }
        return $user;
    }

    public function verifyPasswordChange(string $token, string $hashedPassword): bool
    {
        $user = $this->getUserByVerificationToken($token);
        if (!$user) {
            return false;
        }
        $user->setPassword($hashedPassword);
        $this->clearToken($user);
        return true;
    }

    private function isTokenValid(User $user): bool
    {
        $expires = $user->getEmailVerificationTokenExpiresAt();
        return $expires && $expires >= new \DateTimeImmutable();
    }

    private function clearToken(User $user): void
    {
        $user->setEmailVerificationToken(null);
        $user->setEmailVerificationTokenExpiresAt(null);
        $user->setUpdatedAt(new \DateTimeImmutable());
        $this->userRepository->getEntityManager()->flush();
    }
}

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
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(value: '%env(default:default_mailer_from:MAILER_FROM)%')]
        private readonly string $mailerFrom,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(value: '%env(default:default_mailer_from_name:MAILER_FROM_NAME)%')]
        private readonly string $mailerFromName,
    ) {
    }

    public function sendEmailChangeVerification(User $user, string $newEmail): void
    {
        $token = $this->issueVerificationToken($user);

        $verifyUrl = $this->appUrl . '/verify-email?token=' . $token . '&type=email&value=' . urlencode($newEmail);
        $body = "Bonjour,\n\nCliquez sur le lien suivant pour valider le changement d'adresse email :\n" . $verifyUrl . "\n\nCe lien expire dans 24 heures.";

        $email = (new Email())
            ->from($this->fromAddress())
            ->to($newEmail)
            ->subject('Vérification changement d\'email - OMtek')
            ->text($body);
        $this->mailer->send($email);
    }

    public function sendPasswordChangeVerification(User $user): void
    {
        $token = $this->issueVerificationToken($user);

        $verifyUrl = $this->appUrl . '/verify-email?token=' . $token . '&type=password';
        $body = "Bonjour,\n\nCliquez sur le lien suivant pour valider le changement de mot de passe :\n" . $verifyUrl . "\n\nCe lien expire dans 24 heures.";

        $email = (new Email())
            ->from($this->fromAddress())
            ->to($user->getEmail())
            ->subject('Vérification changement de mot de passe - OMtek')
            ->text($body);
        $this->mailer->send($email);
    }

    /**
     * Envoi apres creation d'un compte par un admin.
     * Le destinataire definit lui-meme son mot de passe via un lien securise.
     */
    public function sendNewAccountPasswordSetup(User $user): void
    {
        $token = $this->issueVerificationToken($user);
        $verifyUrl = $this->appUrl . '/verify-email?token=' . $token . '&type=password';

        $roles = array_values(array_filter(
            $user->getRoles(),
            static fn (string $role): bool => $role !== 'ROLE_USER'
        ));
        $rolesText = $roles !== [] ? implode(', ', $roles) : User::ROLE_TECH;

        $body = "Bonjour,\n\n"
            . "Un compte OMtek vient d'etre cree pour vous.\n\n"
            . "Email de connexion : " . $user->getEmail() . "\n"
            . "Role : " . $rolesText . "\n\n"
            . "Definissez votre mot de passe en cliquant sur le lien suivant :\n"
            . $verifyUrl . "\n\n"
            . "Ce lien expire dans 24 heures.\n"
            . "Si vous n'etes pas concerne, ignorez cet email.";

        $email = (new Email())
            ->from($this->fromAddress())
            ->to($user->getEmail())
            ->subject('Activation de votre compte OMtek')
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

    private function issueVerificationToken(User $user): string
    {
        $token = bin2hex(random_bytes(32));
        $user->setEmailVerificationToken($token);
        $user->setEmailVerificationTokenExpiresAt(new \DateTimeImmutable(self::TOKEN_TTL));
        $user->setUpdatedAt(new \DateTimeImmutable());
        $this->userRepository->getEntityManager()->flush();

        return $token;
    }

    private function fromAddress(): Address
    {
        return new Address($this->mailerFrom, $this->mailerFromName);
    }
}

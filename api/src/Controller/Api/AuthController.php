<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\EmailVerificationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/auth', name: 'api_auth_')]
class AuthController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    /**
     * POST /api/auth/login
     * Body: { "email": "...", "password": "..." }
     * Returns: { "token", "expiresAt", "user": { id, email, firstName, lastName, roles } }
     */
    #[Route('/login', name: 'login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!\is_array($data) || empty($data['email']) || empty($data['password'])) {
            return new JsonResponse(['error' => 'Email et mot de passe requis'], Response::HTTP_BAD_REQUEST);
        }
        $email = trim((string) $data['email']);
        $password = (string) $data['password'];

        $user = $this->userRepository->findOneBy(['email' => $email]);
        if (!$user || !$this->passwordHasher->isPasswordValid($user, $password)) {
            return new JsonResponse(['error' => 'Identifiants invalides'], Response::HTTP_UNAUTHORIZED);
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = new \DateTimeImmutable('+30 days');
        $user->setApiToken($token);
        $user->setApiTokenExpiresAt($expiresAt);
        $user->setUpdatedAt(new \DateTimeImmutable());
        $this->userRepository->getEntityManager()->flush();

        return new JsonResponse([
            'token' => $token,
            'expiresAt' => $expiresAt->format(\DateTimeInterface::ATOM),
            'user' => $this->userToArray($user),
        ], Response::HTTP_OK);
    }

    /**
     * POST /api/auth/logout (invalide le token côté client ; le serveur pourrait révoquer)
     */
    #[Route('/logout', name: 'logout', methods: ['POST'])]
    public function logout(): JsonResponse
    {
        $user = $this->getUser();
        if ($user instanceof User) {
            $user->setApiToken(null);
            $user->setApiTokenExpiresAt(null);
            $user->setUpdatedAt(new \DateTimeImmutable());
            $this->userRepository->getEntityManager()->flush();
        }
        return new JsonResponse(['ok' => true], Response::HTTP_OK);
    }

    /**
     * POST /api/auth/verify : vérifier token email (changement email ou mot de passe).
     * Body: { "token", "type": "email"|"password", "value"?: "nouvel email", "newPassword"?: "..." }
     */
    #[Route('/verify', name: 'verify', methods: ['POST'])]
    public function verify(Request $request, EmailVerificationService $emailVerification, UserPasswordHasherInterface $passwordHasher): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!\is_array($data) || empty($data['token']) || empty($data['type'])) {
            return new JsonResponse(['error' => 'token et type requis'], Response::HTTP_BAD_REQUEST);
        }
        $token = (string) $data['token'];
        $type = (string) $data['type'];
        if ($type === 'email' && !empty($data['value'])) {
            $ok = $emailVerification->verifyEmailChange($token, (string) $data['value']);
        } elseif ($type === 'password' && !empty($data['newPassword'])) {
            $user = $emailVerification->getUserByVerificationToken($token);
            if (!$user) {
                return new JsonResponse(['error' => 'Token invalide ou expiré'], Response::HTTP_BAD_REQUEST);
            }
            $hashed = $passwordHasher->hashPassword($user, (string) $data['newPassword']);
            $ok = $emailVerification->verifyPasswordChange($token, $hashed);
        } else {
            return new JsonResponse(['error' => 'Paramètres invalides'], Response::HTTP_BAD_REQUEST);
        }
        if (!$ok) {
            return new JsonResponse(['error' => 'Token invalide ou expiré'], Response::HTTP_BAD_REQUEST);
        }
        return new JsonResponse(['message' => 'Vérification effectuée'], Response::HTTP_OK);
    }

    /**
     * GET /api/auth/me : utilisateur connecté
     */
    #[Route('/me', name: 'me', methods: ['GET'])]
    public function me(): JsonResponse|Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }
        return new JsonResponse($this->userToArray($user), Response::HTTP_OK);
    }

    private function userToArray(User $user): array
    {
        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'roles' => $user->getRoles(),
            'emailVerified' => $user->isEmailVerified(),
        ];
    }
}

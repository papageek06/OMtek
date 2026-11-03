<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\EmailVerificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/users', name: 'api_users_')]
class UserController extends AbstractController
{
    private const ASSIGNABLE_ROLES = [
        User::ROLE_ADMIN,
        User::ROLE_TECH,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ValidatorInterface $validator,
    ) {
    }

    /**
     * POST /api/users : créer un utilisateur (admin uniquement).
     * Body: { "email", "password", "firstName", "lastName", "roles": ["ROLE_TECH"] ou ["ROLE_ADMIN"] }
     */
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): JsonResponse|Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $users = $this->userRepository->findBy([], ['createdAt' => 'DESC', 'id' => 'DESC']);

        return new JsonResponse(array_map(
            fn (User $user): array => $this->userToArray($user),
            $users
        ), Response::HTTP_OK);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request, EmailVerificationService $emailVerification): JsonResponse|Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data) || empty($data['email'])) {
            return new JsonResponse(['error' => 'Email requis'], Response::HTTP_BAD_REQUEST);
        }

        $email = trim((string) $data['email']);
        if ($this->userRepository->findOneBy(['email' => $email])) {
            return new JsonResponse(['error' => 'Cet email est déjà utilisé'], Response::HTTP_CONFLICT);
        }

        $user = new User();
        $user->setEmail($email);
        $plainPassword = trim((string) ($data['password'] ?? ''));
        if ($plainPassword === '') {
            // Mot de passe provisoire inutilisable cote admin : l'utilisateur doit passer par le lien email.
            $plainPassword = bin2hex(random_bytes(16));
        }
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        $user->setFirstName(trim((string) ($data['firstName'] ?? '')));
        $user->setLastName(trim((string) ($data['lastName'] ?? '')));
        $requestedRoles = $data['roles'] ?? [User::ROLE_TECH];
        if (!\is_array($requestedRoles)) {
            return new JsonResponse(['error' => 'Le champ roles doit etre un tableau'], Response::HTTP_BAD_REQUEST);
        }

        $normalizedRoles = array_values(array_unique(array_filter(array_map(
            static fn (mixed $role): string => \is_string($role) ? strtoupper(trim($role)) : '',
            $requestedRoles
        ))));

        if (\in_array(User::ROLE_SUPER_ADMIN, $normalizedRoles, true)) {
            return new JsonResponse([
                'error' => 'La creation de ROLE_SUPER_ADMIN via API est interdite. Utiliser la commande de bootstrap.',
            ], Response::HTTP_FORBIDDEN);
        }

        $invalidRoles = array_values(array_diff($normalizedRoles, self::ASSIGNABLE_ROLES));
        if ($invalidRoles !== []) {
            return new JsonResponse([
                'error' => 'Roles invalides',
                'details' => $invalidRoles,
                'allowedRoles' => self::ASSIGNABLE_ROLES,
            ], Response::HTTP_BAD_REQUEST);
        }

        $user->setRoles($normalizedRoles !== [] ? $normalizedRoles : [User::ROLE_TECH]);

        $violations = $this->validator->validate($user);
        if ($violations->count() > 0) {
            $errs = [];
            foreach ($violations as $v) {
                $errs[$v->getPropertyPath()] = $v->getMessage();
            }
            return new JsonResponse(['errors' => $errs], Response::HTTP_BAD_REQUEST);
        }

        $this->em->persist($user);
        $this->em->flush();

        $mailSent = true;
        $warning = null;
        try {
            $emailVerification->sendNewAccountPasswordSetup($user);
        } catch (TransportExceptionInterface) {
            $mailSent = false;
            $warning = 'Utilisateur cree, mais email non envoye (erreur SMTP).';
        }

        $response = $this->userToArray($user);
        $response['mailSent'] = $mailSent;
        $response['warning'] = $warning;

        return new JsonResponse($response, Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse|Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $targetUser = $this->userRepository->find($id);
        if (!$targetUser instanceof User) {
            return new JsonResponse(['error' => 'Utilisateur introuvable'], Response::HTTP_NOT_FOUND);
        }

        $currentUser = $this->getUser();
        if ($currentUser instanceof User && $currentUser->getId() === $targetUser->getId()) {
            return new JsonResponse(['error' => 'Suppression de votre propre compte interdite'], Response::HTTP_BAD_REQUEST);
        }

        if ($targetUser->isSuperAdmin()) {
            return new JsonResponse(['error' => 'Suppression de ROLE_SUPER_ADMIN interdite'], Response::HTTP_FORBIDDEN);
        }

        $this->em->remove($targetUser);
        $this->em->flush();

        return new JsonResponse(['ok' => true], Response::HTTP_OK);
    }

    /**
     * PATCH /api/users/{id}/access : role et droits fins d'un utilisateur (admin uniquement).
     * Body: { "role"?: "ROLE_ADMIN"|"ROLE_TECH", "permissions"?: ["ROLE_PERM_PIECES", ...] }
     * Un admin ne peut pas modifier ses propres acces ni ceux d'un super admin.
     */
    #[Route('/{id}/access', name: 'access_update', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function updateAccess(int $id, Request $request, LoggerInterface $logger): JsonResponse|Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $targetUser = $this->userRepository->find($id);
        if (!$targetUser instanceof User) {
            return new JsonResponse(['error' => 'Utilisateur introuvable'], Response::HTTP_NOT_FOUND);
        }

        $currentUser = $this->getUser();
        if ($currentUser instanceof User && $currentUser->getId() === $targetUser->getId()) {
            return new JsonResponse(['error' => 'Modification de vos propres acces interdite'], Response::HTTP_BAD_REQUEST);
        }
        if ($targetUser->isSuperAdmin()) {
            return new JsonResponse(['error' => 'Les acces d\'un super admin ne sont pas modifiables'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        $before = ['roles' => $targetUser->getRoles(), 'permissions' => $targetUser->getPermissions()];

        if (\array_key_exists('role', $data)) {
            $role = \is_string($data['role']) ? strtoupper(trim($data['role'])) : '';
            if (!\in_array($role, self::ASSIGNABLE_ROLES, true)) {
                return new JsonResponse(['error' => 'Role invalide', 'allowedRoles' => self::ASSIGNABLE_ROLES], Response::HTTP_BAD_REQUEST);
            }
            $targetUser->setRoles([$role]);
        }

        if (\array_key_exists('permissions', $data)) {
            if (!\is_array($data['permissions'])) {
                return new JsonResponse(['error' => 'Le champ permissions doit etre un tableau'], Response::HTTP_BAD_REQUEST);
            }
            $requested = array_values(array_unique(array_map(
                static fn (mixed $p): string => \is_string($p) ? strtoupper(trim($p)) : '',
                $data['permissions']
            )));
            $invalid = array_values(array_diff($requested, User::PERMISSIONS));
            if ($invalid !== []) {
                return new JsonResponse(['error' => 'Droits invalides', 'details' => $invalid, 'allowedPermissions' => User::PERMISSIONS], Response::HTTP_BAD_REQUEST);
            }
            $targetUser->setPermissions($requested);
        }

        $targetUser->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();

        $logger->warning('Acces utilisateur modifies', [
            'targetUserId' => $targetUser->getId(),
            'targetEmail' => $targetUser->getEmail(),
            'by' => $currentUser instanceof User ? $currentUser->getEmail() : null,
            'before' => $before,
            'after' => ['roles' => $targetUser->getRoles(), 'permissions' => $targetUser->getPermissions()],
        ]);

        return new JsonResponse($this->userToArray($targetUser), Response::HTTP_OK);
    }

    /**
     * GET /api/auth/profile ou PATCH : profil de l'utilisateur connecté.
     * On utilise /api/users/me pour cohérence.
     */
    #[Route('/me', name: 'me', methods: ['GET', 'PATCH'])]
    public function me(Request $request, EmailVerificationService $emailVerification): JsonResponse|Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        if ($request->isMethod('GET')) {
            return new JsonResponse($this->userToArray($user), Response::HTTP_OK);
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        if (isset($data['firstName'])) {
            $user->setFirstName(trim((string) $data['firstName']));
        }
        if (isset($data['lastName'])) {
            $user->setLastName(trim((string) $data['lastName']));
        }
        if (isset($data['email']) && $data['email'] !== $user->getEmail()) {
            $newEmail = trim((string) $data['email']);
            if ($this->userRepository->findOneBy(['email' => $newEmail])) {
                return new JsonResponse(['error' => 'Cet email est déjà utilisé'], Response::HTTP_CONFLICT);
            }
            $emailVerification->sendEmailChangeVerification($user, $newEmail);
            return new JsonResponse([
                'message' => 'Un email de vérification a été envoyé pour valider le changement d\'adresse.',
                'user' => $this->userToArray($user),
            ], Response::HTTP_OK);
        }
        if (isset($data['currentPassword']) && isset($data['newPassword'])) {
            if (!$this->passwordHasher->isPasswordValid($user, (string) $data['currentPassword'])) {
                return new JsonResponse(['error' => 'Mot de passe actuel incorrect'], Response::HTTP_BAD_REQUEST);
            }
            $emailVerification->sendPasswordChangeVerification($user);
            return new JsonResponse([
                'message' => 'Un email de vérification a été envoyé pour valider le changement de mot de passe.',
                'user' => $this->userToArray($user),
            ], Response::HTTP_OK);
        }

        $user->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();

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
            'permissions' => $user->getPermissions(),
            'emailVerified' => $user->isEmailVerified(),
        ];
    }
}

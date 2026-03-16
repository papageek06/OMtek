<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:user:bootstrap-admin',
    description: 'Bootstrap the first admin account for production setup.',
    aliases: ['app:user:create-super-admin'],
)]
final class CreateSuperAdminCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Bootstrap admin email')
            ->addOption('password', null, InputOption::VALUE_OPTIONAL, 'Bootstrap admin password')
            ->addOption('first-name', null, InputOption::VALUE_OPTIONAL, 'First name', 'Super')
            ->addOption('last-name', null, InputOption::VALUE_OPTIONAL, 'Last name', 'Admin')
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Allow creating another super admin even when an admin already exists.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = strtolower(trim((string) $input->getOption('email')));
        $password = (string) $input->getOption('password');
        $firstName = trim((string) $input->getOption('first-name')) ?: 'Super';
        $lastName = trim((string) $input->getOption('last-name')) ?: 'Admin';
        $force = (bool) $input->getOption('force');

        if ($email === '') {
            $io->error('Option --email is required.');
            return Command::FAILURE;
        }

        /** @var list<User> $users */
        $users = $this->em->getRepository(User::class)->findAll();
        $existing = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);

        if ($existing instanceof User && $this->isPrivilegedUser($existing)) {
            $io->success(sprintf('Bootstrap already done. Admin account "%s" exists.', $email));
            return Command::SUCCESS;
        }

        if ($existing instanceof User) {
            $io->error(sprintf('Email "%s" is already used by a non-admin account.', $email));
            return Command::FAILURE;
        }

        $hasPrivilegedUser = false;
        foreach ($users as $candidate) {
            if ($this->isPrivilegedUser($candidate)) {
                $hasPrivilegedUser = true;
                break;
            }
        }

        if ($hasPrivilegedUser && !$force) {
            $io->error(
                'An admin account already exists. Use this command only for first production bootstrap, or rerun with --force.'
            );
            return Command::FAILURE;
        }

        if (trim($password) === '') {
            $io->error('Option --password is required when creating a new bootstrap admin.');
            return Command::FAILURE;
        }

        $user = new User();
        $user->setEmail($email);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $user->setFirstName($firstName);
        $user->setLastName($lastName);
        $user->setRoles([User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN]);
        $user->setEmailVerified(true);

        $this->em->persist($user);
        $this->em->flush();

        $io->success(sprintf('Bootstrap super admin created: %s', $email));
        return Command::SUCCESS;
    }

    private function isPrivilegedUser(User $user): bool
    {
        return $user->isAdmin() || $user->isSuperAdmin();
    }
}

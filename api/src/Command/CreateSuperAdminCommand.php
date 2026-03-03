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
    name: 'app:user:create-super-admin',
    description: 'Crée un super administrateur (premier utilisateur).',
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
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Email du super admin')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Mot de passe')
            ->addOption('first-name', null, InputOption::VALUE_OPTIONAL, 'Prénom', 'Super')
            ->addOption('last-name', null, InputOption::VALUE_OPTIONAL, 'Nom', 'Admin');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = $input->getOption('email');
        $password = $input->getOption('password');
        if (!$email || !$password) {
            $io->error('Options --email et --password requises.');
            return Command::FAILURE;
        }
        $existing = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existing) {
            $io->error("Un utilisateur avec l'email {$email} existe déjà.");
            return Command::FAILURE;
        }
        $user = new User();
        $user->setEmail(trim($email));
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $user->setFirstName($input->getOption('first-name'));
        $user->setLastName($input->getOption('last-name'));
        $user->setRoles([User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN]);
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();
        $io->success("Super admin créé : {$email}");
        return Command::SUCCESS;
    }
}

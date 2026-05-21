<?php

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:create-user', description: 'Create a new user')]
class CreateUserCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email address')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Display name')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Grant ROLE_ADMIN')
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'Password (prompted if omitted)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $email = $input->getArgument('email');

        $name = $input->getOption('name')
            ?? $io->ask('Display name (leave blank to use email prefix)');

        $password = $input->getOption('password')
            ?? $io->askHidden('Password', fn ($v) => $v ?: throw new \RuntimeException('Password cannot be empty.'));

        $user = new User();
        $user->setEmail($email);
        $user->setName($name ?: null);
        $user->setRoles($input->getOption('admin') ? ['ROLE_ADMIN'] : []);
        $user->setPassword($this->hasher->hashPassword($user, $password));

        $this->em->persist($user);
        $this->em->flush();

        $role = $input->getOption('admin') ? 'admin' : 'user';
        $io->success(sprintf('Created %s: %s (%s)', $role, $user->getDisplayName(), $email));

        return Command::SUCCESS;
    }
}

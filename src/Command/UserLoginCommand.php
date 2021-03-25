<?php

namespace ItkDev\OpenIdConnectBundle\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

class UserLoginCommand extends Command
{
    protected static $defaultName = 'itk-dev:openid-connect:login';
    protected static $defaultDescription = 'Get login url for user';
    /**
     * @var UrlGeneratorInterface
     */
    private $urlGenerator;
    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    public function __construct(UrlGeneratorInterface $urlGenerator, EntityManagerInterface $entityManager)
    {
        $this->urlGenerator = $urlGenerator;
        $this->entityManager = $entityManager;

        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setDescription(self::$defaultDescription)
            ->addArgument('email', InputArgument::REQUIRED, 'User email');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = $input->getArgument('email');

        // Lookup user in database
        $user = $this->entityManager->getRepository(User::class)
            ->findOneBy(['email' => $email]);
        if (null === $user) {
            throw new RuntimeException('User not found in database');
        }
        // Generate new token and set it on the user
        $token = Uuid::v4()->toBase32();
        $user->setLoginToken($token);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        //Generate absolute url for login
        $loginPage = $this->urlGenerator->generate('homepage', [
            'loginToken' => $token,
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        $io->writeln($loginPage);

        return Command::SUCCESS;
    }
}

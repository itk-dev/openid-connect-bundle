<?php

namespace ItkDev\OpenIdConnectBundle\Command;

use ItkDev\OpenIdConnectBundle\Util\CliLoginHelper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\UsernameNotFoundException;
use Symfony\Component\Security\Core\User\UserProviderInterface;

class UserLoginCommand extends Command
{
    protected static $defaultName = 'itk-dev:openid-connect:login';
    protected static $defaultDescription = 'Get login url for user';
    /**
     * @var UrlGeneratorInterface
     */
    private $urlGenerator;

    /**
     * @var CliLoginHelper
     */
    private $cliLoginHelper;

    private $userProvider;

    public function __construct(CliLoginHelper $cliLoginHelper, UrlGeneratorInterface $urlGenerator, UserProviderInterface $userProvider)
    {
        $this->cliLoginHelper = $cliLoginHelper;
        $this->urlGenerator = $urlGenerator;
        $this->userProvider = $userProvider;

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
        // Use CliLoginHelper to check table setup correct
        $this->cliLoginHelper->ensureInitialized();
        $io = new SymfonyStyle($input, $output);
        $email = $input->getArgument('email');

        // Check if email is registered in User database
        // todo: possibly avoid beneath
        try {
            $this->userProvider->loadUserByUsername($email);
        } catch (UsernameNotFoundException $e) {
            throw new \Exception('User does not exist');
        }

        // Create token via CliLoginHelper
        $token = $this->cliLoginHelper->createToken($email);

        //Generate absolute url for login
        $loginPage = $this->urlGenerator->generate('homepage', [
            'loginToken' => $token,
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        $io->writeln($loginPage);

        return Command::SUCCESS;
    }
}

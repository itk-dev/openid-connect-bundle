<?php

namespace ItkDev\OpenIdConnectBundle\Command;

use ItkDev\OpenIdConnectBundle\Exception\CacheException;
use ItkDev\OpenIdConnectBundle\Util\CliLoginHelper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserProviderInterface;

class UserLoginCommand extends Command
{
    protected static $defaultName = 'itk-dev:openid-connect:login';
    protected static $defaultDescription = 'Get login url for user';

    /**
     * UserLoginCommand constructor.
     *
     * @param CliLoginHelper $cliLoginHelper
     * @param string $cliLoginRedirectRoute
     * @param UrlGeneratorInterface $urlGenerator
     * @param UserProviderInterface $userProvider
     */
    public function __construct(
        private readonly CliLoginHelper $cliLoginHelper,
        private readonly string $cliLoginRedirectRoute,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly UserProviderInterface $userProvider
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription(self::$defaultDescription)
            ->addArgument('username', InputArgument::REQUIRED, 'Username');
    }

    /**
     * Executes the CLI login url generation.
     *
     * @throws CacheException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $username = $input->getArgument('username');

        // Check if username is registered in User database
        try {
            $this->userProvider->loadUserByIdentifier($username);
        } catch (UserNotFoundException) {
            $io->error('User does not exist');

            return Command::FAILURE;
        }

        // Create token via CliLoginHelper
        $token = $this->cliLoginHelper->createToken($username);

        // Generate absolute url for login
        $loginPage = $this->urlGenerator->generate($this->cliLoginRedirectRoute, [
            'loginToken' => $token,
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        $io->writeln($loginPage);

        return Command::SUCCESS;
    }
}

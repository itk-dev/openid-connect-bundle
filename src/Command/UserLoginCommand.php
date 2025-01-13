<?php

namespace ItkDev\OpenIdConnectBundle\Command;

use ItkDev\OpenIdConnectBundle\Exception\CacheException;
use ItkDev\OpenIdConnectBundle\Util\CliLoginHelper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserProviderInterface;

#[AsCommand(
    name: 'itk-dev:openid-connect:login',
    description: 'Get login url for user',
)]
class UserLoginCommand extends Command
{
    /**
     * UserLoginCommand constructor.
     *
     * @param CliLoginHelper $cliLoginHelper
     * @param string $cliLoginRoute
     * @param UrlGeneratorInterface $urlGenerator
     * @param UserProviderInterface $userProvider
     */
    public function __construct(
        private readonly CliLoginHelper $cliLoginHelper,
        private readonly string $cliLoginRoute,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly UserProviderInterface $userProvider
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
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
        $loginPage = $this->urlGenerator->generate($this->cliLoginRoute, [
            'loginToken' => $token,
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        $io->writeln($loginPage);

        return Command::SUCCESS;
    }
}

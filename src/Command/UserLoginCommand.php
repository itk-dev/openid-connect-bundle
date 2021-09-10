<?php

namespace ItkDev\OpenIdConnectBundle\Command;

use ItkDev\OpenIdConnectBundle\Exception\UserDoesNotExistException;
use ItkDev\OpenIdConnectBundle\Exception\UsernameDoesNotExistException;
use ItkDev\OpenIdConnectBundle\Util\CliLoginHelper;
use Psr\Cache\InvalidArgumentException;
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

    /**
     * @var UserProviderInterface
     */
    private $userProvider;

    /**
     * @var string
     */
    private $cliLoginRedirectRoute;

    /**
     * UserLoginCommand constructor.
     *
     * @param CliLoginHelper $cliLoginHelper
     * @param string $cliLoginRedirectRoute
     * @param UrlGeneratorInterface $urlGenerator
     * @param UserProviderInterface $userProvider
     */

    public function __construct(CliLoginHelper $cliLoginHelper, string $cliLoginRedirectRoute, UrlGeneratorInterface $urlGenerator, UserProviderInterface $userProvider)
    {
        $this->cliLoginHelper = $cliLoginHelper;
        $this->cliLoginRedirectRoute = $cliLoginRedirectRoute;
        $this->urlGenerator = $urlGenerator;
        $this->userProvider = $userProvider;

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
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return int
     * @throws InvalidArgumentException
     * @throws UserDoesNotExistException
     * @throws UsernameDoesNotExistException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $username = $input->getArgument('username');

        if (!is_string($username)) {
            throw new UsernameDoesNotExistException('Username is not type string.');
        }

        // Check if username is registered in User database
        try {
            $this->userProvider->loadUserByUsername($username);
        } catch (UsernameNotFoundException $e) {
            throw new UserDoesNotExistException('User does not exist.');
        }

        // Create token via CliLoginHelper
        $token = $this->cliLoginHelper->createToken($username);

        //Generate absolute url for login
        $loginPage = $this->urlGenerator->generate($this->cliLoginRedirectRoute, [
            'loginToken' => $token,
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        $io->writeln($loginPage);

        return Command::SUCCESS;
    }
}

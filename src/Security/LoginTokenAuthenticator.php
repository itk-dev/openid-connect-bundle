<?php

namespace ItkDev\OpenIdConnectBundle\Security;

use ItkDev\OpenIdConnectBundle\Util\CliLoginHelper;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\UsernameNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Guard\AbstractGuardAuthenticator;

class LoginTokenAuthenticator extends AbstractGuardAuthenticator
{
    /**
     * @var CliLoginHelper
     */
    private $cliLoginHelper;

    /**
     * @var UrlGeneratorInterface
     */
    private $router;

    /**
     * @var string
     */
    private $cliLoginRedirectRoute;

    public function __construct(CliLoginHelper $cliLoginHelper, string $cliLoginRedirectRoute, UrlGeneratorInterface $router)
    {
        $this->cliLoginHelper = $cliLoginHelper;
        $this->cliLoginRedirectRoute = $cliLoginRedirectRoute;
        $this->router = $router;
    }

    public function supports(Request $request)
    {
        return $request->query->has('loginToken');
    }

    public function getCredentials(Request $request)
    {
        return $request->query->get('loginToken');
    }

    public function getUser($credentials, UserProviderInterface $userProvider)
    {
        if (null === $credentials) {
            // The token header was empty, authentication fails with HTTP Status
            // Code 401 "Unauthorized"
            return null;
        }

        // Get username from CliHelperLogin
        $username = $this->cliLoginHelper->getUsername($credentials);

        try {
            $user = $userProvider->loadUserByUsername($username);
        } catch (UsernameNotFoundException $e) {
            throw new \Exception('Token correct but user not found');
        }

        return $user;
    }

    public function checkCredentials($credentials, UserInterface $user)
    {
        // No credentials to check since loginToken login
        return true;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception)
    {
        // Throw (telling) error
        throw new AuthenticationException('Error occurred validating login token');
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $providerKey)
    {
        return new RedirectResponse($this->router->generate($this->cliLoginRedirectRoute));
    }

    public function start(Request $request, AuthenticationException $authException = null)
    {
        // Only way to start the CLI login flow should be via CMD and URL
        throw new AuthenticationException('Authentication needed to access this URI/resource.');
    }

    public function supportsRememberMe()
    {
        return false;
    }
}

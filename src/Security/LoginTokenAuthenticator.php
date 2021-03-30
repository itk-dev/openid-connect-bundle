<?php

namespace ItkDev\OpenIdConnectBundle\Security;

use Doctrine\ORM\EntityManagerInterface;
use ItkDev\OpenIdConnectBundle\Util\CliLoginHelper;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\UsernameNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Guard\AbstractGuardAuthenticator;

abstract class LoginTokenAuthenticator extends AbstractGuardAuthenticator
{
    /**
     * @var CliLoginHelper
     */
    private $cliLoginHelper;

    public function __construct(CliLoginHelper $cliLoginHelper)
    {
        $this->cliLoginHelper = $cliLoginHelper;
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


        // @todo Possibly dont just beneath if start() is implemented in project
        // $user = $userProvider->loadUserByUsername($username);

        // Fix for tests
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

    abstract public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $providerKey);

    abstract public function start(Request $request, AuthenticationException $authException = null);

    public function supportsRememberMe()
    {
        return false;
    }
}

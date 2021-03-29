<?php

namespace ItkDev\OpenIdConnectBundle\Security;

use Doctrine\ORM\EntityManagerInterface;
use ItkDev\OpenIdConnectBundle\Util\CliLoginHelper;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationCredentialsNotFoundException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\UsernameNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Guard\AbstractGuardAuthenticator;

abstract class LoginTokenAuthenticator extends AbstractGuardAuthenticator
{
    private $cliLoginHelper;

    /**
     * @var EntityManagerInterface
     */
    private $entityManager;


    public function __construct(CliLoginHelper $cliLoginHelper, EntityManagerInterface $entityManager)
    {
        $this->cliLoginHelper = $cliLoginHelper;
        $this->entityManager = $entityManager;
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

        // Get user from CliHelperLogin
        $user = $this->cliLoginHelper->getUser($credentials);

//        $user = $this->entityManager->getRepository(User::class)
//            ->findOneBy(['loginToken' => $credentials]);
//
//
//        if (null === $user) {
//            // fail authentication with a custom error
//            throw new AuthenticationCredentialsNotFoundException('Token could not be found.');
//        }
//
//        // User will always be set at this point,
//        // reset token to avoid being able to reuse login url
//        $user->setLoginToken(null);
//        $this->entityManager->persist($user);
//        $this->entityManager->flush();

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

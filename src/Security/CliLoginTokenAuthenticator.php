<?php

namespace ItkDev\OpenIdConnectBundle\Security;

use ItkDev\OpenIdConnectBundle\Exception\CacheException;
use ItkDev\OpenIdConnectBundle\Exception\TokenNotFoundException;
use ItkDev\OpenIdConnectBundle\Exception\UsernameDoesNotExistException;
use ItkDev\OpenIdConnectBundle\Util\CliLoginHelper;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Authenticator class for CLI login.
 */
class CliLoginTokenAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly CliLoginHelper $cliLoginHelper,
        private readonly UserProviderInterface $userProvider,
        private readonly string $cliLoginRoute,
        private readonly UrlGeneratorInterface $router
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $request->query->has('loginToken');
    }

    /**
     * @throws UsernameDoesNotExistException
     */
    public function authenticate(Request $request): Passport
    {
        $token = (string) $request->query->get('loginToken');
        if (empty($token)) {
            // The token header was empty, authentication fails with HTTP Status
            // Code 401 "Unauthorized"
            throw new CustomUserMessageAuthenticationException('No login token provided');
        }

        try {
            $username = $this->cliLoginHelper->getUsername($token);
        } catch (CacheException|TokenNotFoundException) {
            throw new CustomUserMessageAuthenticationException('Cannot get username');
        }

        if (null === $username) {
            throw new UsernameDoesNotExistException('null is not a valid username.');
        }

        return new SelfValidatingPassport(new UserBadge($username));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return new RedirectResponse($this->router->generate($this->cliLoginRoute));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        throw new AuthenticationException('Error occurred validating login token');
    }
}

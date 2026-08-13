<?php

namespace ItkDev\OpenIdConnectBundle\Security;

use ItkDev\OpenIdConnectBundle\Exception\CacheException;
use ItkDev\OpenIdConnectBundle\Exception\TokenNotFoundException;
use ItkDev\OpenIdConnectBundle\Exception\UsernameDoesNotExistException;
use ItkDev\OpenIdConnectBundle\Util\CliLoginHelper;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
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
        private readonly string $cliLoginRoute,
        private readonly UrlGeneratorInterface $router,
        private readonly LoggerInterface $logger,
        private readonly string $logLevel = LogLevel::ERROR,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $request->query->has('loginToken');
    }

    /**
     * @throws CustomUserMessageAuthenticationException No token provided, or the token could not be resolved to a username
     * @throws UsernameDoesNotExistException            Token resolved to a null username
     */
    public function authenticate(Request $request): Passport
    {
        $token = (string) $request->query->get('loginToken');
        if ('' === $token) {
            // The token header was empty, authentication fails with HTTP Status
            // Code 401 "Unauthorized"
            $this->logger->log($this->logLevel, 'CLI login failed: no login token provided');

            throw new CustomUserMessageAuthenticationException('No login token provided');
        }

        try {
            $username = $this->cliLoginHelper->getUsername($token);
        } catch (CacheException|TokenNotFoundException $e) {
            $this->logger->log($this->logLevel, 'CLI login failed: cannot resolve token to a username', ['exception' => $e]);

            throw new CustomUserMessageAuthenticationException('Cannot get username', previous: $e);
        }

        if (null === $username) {
            $this->logger->log($this->logLevel, 'CLI login failed: token resolved to a null username');

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
        // Preserve the cause so logs and error reporters can see what actually
        // failed (empty token, cache miss, unknown username, etc.). Symfony's
        // security component renders only the safe message key to the user.
        throw new AuthenticationException(sprintf('Error occurred validating login token: %s', $exception->getMessage()), $exception->getCode(), $exception);
    }
}

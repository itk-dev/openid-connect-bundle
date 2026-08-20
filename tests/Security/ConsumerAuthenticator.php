<?php

namespace ItkDev\OpenIdConnectBundle\Tests\Security;

use ItkDev\OpenIdConnect\Exception\OpenIdConnectExceptionInterface;
use ItkDev\OpenIdConnectBundle\Security\OpenIdLoginAuthenticator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * A subclass written the way the README tells consumers to write one.
 *
 * Two differences from TestAuthenticator, and they are the reason this fixture
 * exists: it converts the bundle's exceptions into an `AuthenticationException`,
 * which is what routes a failure into `onAuthenticationFailure()` in the first
 * place, and it answers `start()` with a redirect to the login route, as a real
 * consumer does. Those two together are the loop's ingredients, so a fixture
 * without them cannot reproduce it.
 */
class ConsumerAuthenticator extends OpenIdLoginAuthenticator
{
    public const string LOGIN_PATH = '/openidconnect/login/test_provider_1';
    public const string FALLBACK_PATH = '/dashboard';

    public function authenticate(Request $request): Passport
    {
        try {
            $claims = $this->validateClaims($request);
        } catch (OpenIdConnectExceptionInterface $exception) {
            throw new CustomUserMessageAuthenticationException($exception->getMessage(), [], 0, $exception);
        }

        return new SelfValidatingPassport(
            new UserBadge(
                $claims['email'],
                fn (string $email) => new TestUser($email)
            )
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // As the README tells consumers to write it.
        return $this->createTargetPathRedirect($request, $firewallName, self::FALLBACK_PATH);
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new RedirectResponse(self::LOGIN_PATH);
    }
}

<?php

namespace ItkDev\OpenIdConnectBundle\Tests\Security;

use ItkDev\OpenIdConnectBundle\Security\OpenIdLoginAuthenticator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

class TestAuthenticator extends OpenIdLoginAuthenticator
{
    /**
     * Claims returned by the last validateClaims() call, exposed so tests
     * can assert on the full claims array (validateClaims is protected).
     *
     * @var array<string, string>
     */
    public array $lastClaims = [];

    public function authenticate(Request $request): Passport
    {
        $claims = $this->validateClaims($request);
        $this->lastClaims = $claims;

        return new SelfValidatingPassport(
            new UserBadge(
                $claims['email'],
                fn (string $email) => new TestUser($email)
            )
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        throw new \LogicException('Test stub: start() is not implemented for this fixture.');
    }

    /**
     * `createTargetPathRedirect()` is protected, as consumers call it from their own
     * `onAuthenticationSuccess()`.
     */
    public function callCreateTargetPathRedirect(Request $request, string $firewallName, string $fallbackUrl): RedirectResponse
    {
        return $this->createTargetPathRedirect($request, $firewallName, $fallbackUrl);
    }
}

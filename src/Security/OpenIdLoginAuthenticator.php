<?php

namespace ItkDev\OpenIdConnectBundle\Security;

use ItkDev\OpenIdConnect\Exception\OpenIdConnectExceptionInterface;
use ItkDev\OpenIdConnect\Exception\ValidationException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Authenticator for OpenId Connect login.
 */
abstract class OpenIdLoginAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    /**
     * OpenIdLoginAuthenticator constructor.
     */
    public function __construct(
        private readonly OpenIdConfigurationProviderManager $providerManager,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        // Check if request has state and code
        return $request->query->has('state') && $request->query->has('code');
    }

    /**
     * Validate oidc claims.
     *
     * @return array<string, string> Array of claims
     *
     * @throws OpenIdConnectExceptionInterface
     */
    protected function validateClaims(Request $request): array
    {
        $session = $request->getSession();
        $providerKey = $session->remove('oauth2provider');
        $providerKey = is_string($providerKey) ? $providerKey : '';
        $provider = $this->providerManager->getProvider($providerKey);

        // Make sure state and oauth2state are the same
        $oauth2state = $session->remove('oauth2state');

        if ($request->query->get('state') !== $oauth2state) {
            throw new ValidationException('Invalid state');
        }

        $oauth2nonce = $session->remove('oauth2nonce');
        if (!is_string($oauth2nonce) || '' === $oauth2nonce) {
            throw new ValidationException('Nonce empty or not found');
        }

        try {
            $code = $request->query->get('code');

            if (!is_string($code)) {
                throw new ValidationException('Missing or invalid code');
            }

            $idToken = $provider->getIdToken($code);
            $claims = $provider->validateIdToken($idToken, $oauth2nonce);
            // Authentication successful
        } catch (OpenIdConnectExceptionInterface $exception) {
            // Handle failed authentication
            throw new ValidationException($exception->getMessage(), previous: $exception);
        }

        /** @var array<string, string> $claimsArray */
        $claimsArray = (array) $claims;

        return $claimsArray + ['open_id_connect_provider' => $providerKey];
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        throw new AuthenticationException('Error occurred validating openid login');
    }
}

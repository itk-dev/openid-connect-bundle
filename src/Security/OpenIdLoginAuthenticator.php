<?php

namespace ItkDev\OpenIdConnectBundle\Security;

use ItkDev\OpenIdConnect\Exception\ItkOpenIdConnectException;
use ItkDev\OpenIdConnect\Exception\ValidationException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Authenticator for OpenId Connect login.
 */
abstract class OpenIdLoginAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    /**
     * @var SessionInterface
     */
    private $session;

    /**
     * @var OpenIdConfigurationProviderManager
     */
    private $providerManager;

    private $leeway;

    public function __construct(
        OpenIdConfigurationProviderManager $providerManager,
        SessionInterface $session,
        int $leeway = 0
    ) {
        $this->providerManager = $providerManager;
        $this->session = $session;
        $this->leeway = $leeway;
    }

    public function supports(Request $request): ?bool
    {
        // Check if request has state and id_token
        return $request->query->has('state') && $request->query->has('id_token');
    }

    /**
     * @throws ValidationException
     */
    protected function getClaims(Request $request)
    {
        $providerKey = (string)$this->session->remove('oauth2provider');
        $provider = $this->providerManager->getProvider($providerKey);

        // Make sure state and oauth2state are the same
        $oauth2state = $this->session->get('oauth2state');
        $this->session->remove('oauth2state');

        if ($request->query->get('state') !== $oauth2state) {
            throw new ValidationException('Invalid state');
        }

        try {
            $idToken = $request->query->get('id_token');

            if (null === $idToken) {
                throw new ValidationException('Id token not found.');
            }

            if (!is_string($idToken)) {
                throw new ValidationException('Id token not type string');
            }

            $claims = $provider->validateIdToken($idToken, $this->session->get('oauth2nonce'), $this->leeway);
            // Authentication successful
        } catch (ItkOpenIdConnectException $exception) {
            // Handle failed authentication
            throw new ValidationException($exception->getMessage());
        } finally {
            $this->session->remove('oauth2nonce');
        }

        return (array)$claims + ['open_id_connect_provider' => $providerKey];
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        throw new AuthenticationException('Error occurred validating openid login');
    }
}

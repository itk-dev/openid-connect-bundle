<?php

namespace ItkDev\OpenIdConnectBundle\Controller;

use ItkDev\OpenIdConnect\Exception\CacheException;
use ItkDev\OpenIdConnect\Exception\HttpException;
use ItkDev\OpenIdConnect\Exception\JsonException;
use ItkDev\OpenIdConnectBundle\Exception\InvalidProviderException;
use ItkDev\OpenIdConnectBundle\Security\OpenIdConfigurationProviderManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Login Controller class.
 */
class LoginController extends AbstractController
{
    public function __construct(
        private readonly OpenIdConfigurationProviderManager $providerManager,
    ) {
    }

    /**
     * Login method redirecting to authorizer.
     *
     * @throws NotFoundHttpException           Provider key not configured (404)
     * @throws ServiceUnavailableHttpException IdP unreachable, returned a non-200, served malformed JSON, or local cache failed (503)
     */
    public function login(Request $request, SessionInterface $session, string $providerKey): RedirectResponse
    {
        try {
            $provider = $this->providerManager->getProvider($providerKey);
        } catch (InvalidProviderException $e) {
            throw new NotFoundHttpException(sprintf('Unknown OIDC provider "%s"', $providerKey), $e);
        }

        $nonce = $provider->generateNonce();
        $state = $provider->generateState();

        // Save to session
        $session->set('oauth2provider', $providerKey);
        $session->set('oauth2state', $state);
        $session->set('oauth2nonce', $nonce);

        try {
            $authUrl = $provider->getAuthorizationUrl([
                'state' => $state,
                'nonce' => $nonce,
                'response_type' => 'code',
                'scope' => 'openid email profile',
            ]);
        } catch (HttpException|JsonException|CacheException $e) {
            // Building the authorization URL fetches the IdP's discovery
            // document. Surface upstream/transport failures as 503 with the
            // cause chained, rather than an unhandled 500.
            throw new ServiceUnavailableHttpException(null, sprintf('Cannot reach OIDC provider "%s"', $providerKey), $e);
        }

        return new RedirectResponse($authUrl);
    }
}

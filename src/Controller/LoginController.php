<?php

namespace ItkDev\OpenIdConnectBundle\Controller;

use ItkDev\OpenIdConnect\Exception\ItkOpenIdConnectException;
use ItkDev\OpenIdConnectBundle\Exception\InvalidProviderException;
use ItkDev\OpenIdConnectBundle\Security\OpenIdConfigurationProviderManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Login Controller class.
 */
class LoginController extends AbstractController
{
    public function __construct(
        private readonly OpenIdConfigurationProviderManager $providerManager
    ) {
    }

    /**
     * Login method redirecting to authorizer.
     *
     * @throws ItkOpenIdConnectException|InvalidProviderException
     */
    public function login(Request $request, SessionInterface $session, string $providerKey): RedirectResponse
    {
        $provider = $this->providerManager->getProvider($providerKey);

        $nonce = $provider->generateNonce();
        $state = $provider->generateState();

        // Save to session
        $session->set('oauth2provider', $providerKey);
        $session->set('oauth2state', $state);
        $session->set('oauth2nonce', $nonce);

        $authUrl = $provider->getAuthorizationUrl([
            'state' => $state,
            'nonce' => $nonce,
            'response_type' => 'code',
            'scope' => 'openid email profile',
        ]);

        return new RedirectResponse($authUrl);
    }
}

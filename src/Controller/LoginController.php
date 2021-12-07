<?php

namespace ItkDev\OpenIdConnectBundle\Controller;

use ItkDev\OpenIdConnect\Exception\ItkOpenIdConnectException;
use ItkDev\OpenIdConnect\Security\OpenIdConfigurationProvider;
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
    /**
     * @var OpenIdConfigurationProviderManager
     */
    private $providerManager;

    public function __construct(OpenIdConfigurationProviderManager $providerManager)
    {
        $this->providerManager = $providerManager;
    }

    /**
     * Login method redirecting to authorizer.
     *
     * @param Request $request
     * @param SessionInterface $session
     * @return RedirectResponse
     * @throws ItkOpenIdConnectException
     */
    public function login(Request $request, SessionInterface $session, string $provider): RedirectResponse
    {
        $theProvider = $this->providerManager->getProvider($provider);

        $nonce = $theProvider->generateNonce();
        $state = $theProvider->generateState();

        // Save to session
        $session->set('oauth2provider', $provider);
        $session->set('oauth2state', $state);
        $session->set('oauth2nonce', $nonce);

        $authUrl = $theProvider->getAuthorizationUrl(['state' => $state, 'nonce' => $nonce]);

        return new RedirectResponse($authUrl);
    }
}

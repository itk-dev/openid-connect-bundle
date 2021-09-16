<?php

namespace ItkDev\OpenIdConnectBundle\Controller;

use ItkDev\OpenIdConnect\Exception\ItkOpenIdConnectException;
use ItkDev\OpenIdConnect\Security\OpenIdConfigurationProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Login Controller class.
 */
class LoginController extends AbstractController
{
    /**
     * @var OpenIdConfigurationProvider
     */
    private $provider;

    public function __construct(OpenIdConfigurationProvider $provider)
    {
        $this->provider = $provider;
    }

    /**
     * Login method redirecting to authorizer.
     *
     * @param SessionInterface $session
     * @return RedirectResponse
     * @throws ItkOpenIdConnectException
     */
    public function login(SessionInterface $session): RedirectResponse
    {
        $nonce = $this->provider->generateNonce();
        $state = $this->provider->generateState();

        // Save to session
        $session->set('oauth2state', $state);
        $session->set('oauth2nonce', $nonce);

        $authUrl = $this->provider->getAuthorizationUrl(['state' => $state, 'nonce' => $nonce]);

        return new RedirectResponse($authUrl);
    }
}

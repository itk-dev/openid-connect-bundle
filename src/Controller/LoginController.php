<?php

namespace ItkDev\OpenIdConnectBundle\Controller;

use ItkDev\OpenIdConnect\Exception\ItkOpenIdConnectException;
use ItkDev\OpenIdConnect\Security\OpenIdConfigurationProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

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
     * @throws ItkOpenIdConnectException
     */
    public function login(SessionInterface $session): Response
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

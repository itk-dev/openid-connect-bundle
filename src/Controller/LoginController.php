<?php

namespace ItkDev\OpenIdConnectBundle\Controller;

use ItkDev\OpenIdConnect\Security\OpenIdConfigurationProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

class LoginController extends AbstractController
{

    /**
     * @var array
     */
    private $openIdProviderOptions;

    public function __construct(array $openIdProviderOptions)
    {
        $this->openIdProviderOptions = $openIdProviderOptions;
    }

    /**
     * @param SessionInterface $session
     * @return Response
     */
    public function login(SessionInterface $session): Response
    {
        $provider = new OpenIdConfigurationProvider($this->openIdProviderOptions);

        $authUrl = $provider->getAuthorizationUrl();

        // Set a oauth2state to avoid CSRF check it in authenticator
        $session->set('oauth2state', $provider->getState());

        return new RedirectResponse($authUrl);
    }
}

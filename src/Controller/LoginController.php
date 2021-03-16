<?php

namespace ItkDev\OpenIdConnectBundle\Controller;

use ItkDev\OpenIdConnect\Security\OpenIdConfigurationProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class LoginController extends AbstractController
{
    /**
     * @Route("/login", name="login")
     */
    public function login(SessionInterface $session, array $openIdProviderOptions = []): Response
    {
        $provider = new OpenIdConfigurationProvider([
                'redirectUri' => $this->generateUrl('', [], UrlGeneratorInterface::ABSOLUTE_URL),
            ] + $openIdProviderOptions);

        $authUrl = $provider->getAuthorizationUrl();

        $session->set('oauth2state', $provider->getState());

        return new RedirectResponse($authUrl);
    }
}
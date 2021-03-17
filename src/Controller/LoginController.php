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
     * @var array
     */
    private $openIdProviderOptions;

    /**
     * @var string
     */
    private $returnRoute;

    public function __construct(array $openIdProviderOptions, string $returnRoute){
        $this->openIdProviderOptions = $openIdProviderOptions;
        $this->returnRoute = $returnRoute;
    }

    /**
     * @Route("/login", name="itk_dev_openid_connect_login")
     * @param SessionInterface $session
     * @return Response
     */
    public function login(SessionInterface $session): Response
    {
        $provider = new OpenIdConfigurationProvider([
                'redirectUri' => $this->generateUrl($this->returnRoute, [], UrlGeneratorInterface::ABSOLUTE_URL),
            ] + $this->openIdProviderOptions);

        $authUrl = $provider->getAuthorizationUrl();

        $session->set('oauth2state', $provider->getState());

        return new RedirectResponse($authUrl);
    }
}
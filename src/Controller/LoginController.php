<?php

namespace ItkDev\OpenIdConnectBundle\Controller;

use ItkDev\OpenIdConnect\Exception\ItkOpenIdConnectException;
use ItkDev\OpenIdConnect\Security\OpenIdConfigurationProvider;
use ItkDev\OpenIdConnectBundle\Exception\InvalidProviderException;
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
     * @var iterable
     */
    private $providers;

    public function __construct(iterable $providers)
    {
        $this->providers = $providers;
    }

    /**
     * Login method redirecting to authorizer.
     *
     * @param Request $request
     * @param SessionInterface $session
     * @return RedirectResponse
     * @throws ItkOpenIdConnectException
     */
    public function login(Request $request, SessionInterface $session): RedirectResponse
    {
        [$providerKey, $provider] = $this->getProvider($request);
        $nonce = $provider->generateNonce();
        $state = $provider->generateState();

        // Save to session
        $session->set('oauth2provider', $providerKey);
        $session->set('oauth2state', $state);
        $session->set('oauth2nonce', $nonce);

        $authUrl = $provider->getAuthorizationUrl(['state' => $state, 'nonce' => $nonce]);

        return new RedirectResponse($authUrl);
    }

    private function getProvider(Request $request): array
    {
        $providerKey = (string)$request->query->get('provider');
        // @see https://symfony.com/index.php/doc/current/service_container/tags.html#tagged-services-with-index
        $providers = $this->providers instanceof \Traversable ? iterator_to_array($this->providers) : $this->providers;
        if (isset($providers[$providerKey])) {
            return [$providerKey, $providers[$providerKey]];
        }

        throw new InvalidProviderException($providerKey);
    }
}

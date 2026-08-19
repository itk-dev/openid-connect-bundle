<?php

namespace ItkDev\OpenIdConnectBundle\Controller;

use ItkDev\OpenIdConnect\Exception\OpenIdConnectExceptionInterface;
use ItkDev\OpenIdConnectBundle\Exception\InvalidProviderException;
use ItkDev\OpenIdConnectBundle\Security\OpenIdConfigurationProviderManager;
use ItkDev\OpenIdConnectBundle\Util\ClientSecretExpiryChecker;
use Psr\Log\LoggerInterface;
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
        private readonly LoggerInterface $logger,
        private readonly ClientSecretExpiryChecker $expiryChecker,
    ) {
    }

    /**
     * Login method redirecting to authorizer.
     *
     * @throws NotFoundHttpException           Provider key not configured (404)
     * @throws ServiceUnavailableHttpException Client secret past its configured expiry, or the IdP is unreachable, returned a non-200, served malformed JSON, or the local cache failed (503)
     * @throws OpenIdConnectExceptionInterface Other provider-init failures (e.g. BadUrlException for a misconfigured metadata_url) — server-side configuration bugs that intentionally bubble as 500
     * @throws \InvalidArgumentException       Declared by league\AbstractProvider::getAuthorizationUrl for missing scope/state. Unreachable in this flow (state always provided, getDefaultScopes() implemented in upstream OpenIdConfigurationProvider). Bubbles as 500 if it ever fires — programmer error.
     */
    public function login(Request $request, SessionInterface $session, string $providerKey): RedirectResponse
    {
        try {
            $provider = $this->providerManager->getProvider($providerKey);
        } catch (InvalidProviderException $e) {
            $this->logger->warning('OIDC login failed: unknown provider', [
                'provider' => $providerKey,
                'exception' => $e,
            ]);

            throw new NotFoundHttpException(sprintf('Unknown OIDC provider "%s"', $providerKey), $e);
        }

        $this->checkClientSecretExpiry($providerKey);

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
        } catch (OpenIdConnectExceptionInterface $e) {
            // Building the authorization URL fetches the IdP's discovery
            // document. Surface upstream/transport/cache failures as 503 with
            // the cause chained, rather than an unhandled 500.
            $this->logger->error('OIDC login failed: cannot reach provider', [
                'provider' => $providerKey,
                'exception' => $e,
            ]);

            throw new ServiceUnavailableHttpException(null, sprintf('Cannot reach OIDC provider "%s"', $providerKey), $e);
        }

        return new RedirectResponse($authUrl);
    }

    /**
     * Refuse to start a round trip that the identity provider will reject anyway.
     *
     * Once the secret has expired the token exchange fails with `invalid_client`,
     * but only at the callback — after the user has been bounced to the IdP and
     * back, with nothing on the way saying why. Stopping here turns that into one
     * clear 503 and a `critical` record naming the provider.
     *
     * @throws ServiceUnavailableHttpException Secret past its configured expiry (503)
     */
    private function checkClientSecretExpiry(string $providerKey): void
    {
        $expiry = $this->expiryChecker->getStatus($providerKey);

        if ($expiry->isExpired()) {
            $this->logger->critical('OIDC login blocked: client secret has expired', $expiry->toArray());

            // The message names the date and both remedies on purpose: the same
            // 503 appears whether the secret really expired or the secret was
            // rotated and `client_secret_expires_at` was left behind, and an
            // operator seeing only "expired" would not think to check the latter.
            throw new ServiceUnavailableHttpException(null, sprintf('The client secret for OIDC provider "%s" expired on %s. Rotate the secret, or update client_secret_expires_at if the secret has already been rotated.', $providerKey, $expiry->expiresAtForHumans()));
        }

        if ($expiry->isExpiringSoon()) {
            // Deliberately not fatal — logins still work — but this is the window
            // in which someone can act before they stop working.
            $this->logger->warning('OIDC client secret expires soon', $expiry->toArray());
        }
    }
}

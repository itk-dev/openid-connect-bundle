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
     * @throws ServiceUnavailableHttpException IdP unreachable, returned a non-200, served malformed JSON, or local cache failed (503)
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
     * Report on the client secret's expiry without standing in the way.
     *
     * Deliberately non-fatal, even once expired. The check trusts a date someone
     * typed, and refusing logins on that basis turns a stale date — a secret
     * rotated without updating the configuration — into a self-inflicted outage.
     * The identity provider is the authority on whether the secret still works;
     * this only makes sure that when it stops working, the reason is already in
     * the log rather than something to be worked out later.
     */
    private function checkClientSecretExpiry(string $providerKey): void
    {
        $expiry = $this->expiryChecker->getStatus($providerKey);

        if ($expiry->isExpired()) {
            // Critical because every login through this provider is expected to
            // fail from here: the token exchange returns invalid_client at the
            // callback. Paired with the failure record logged there, the cause is
            // legible without reproducing anything.
            $this->logger->critical(
                'OIDC client secret is past its configured expiry; logins are expected to fail until it is rotated. If the secret has already been rotated, update client_secret_expires_at.',
                $expiry->toArray(),
            );
        // The statuses are mutually exclusive, so this is an elseif rather than an
        // early return: nothing to guard against, and nothing dead to trip over.
        } elseif ($expiry->isExpiringSoon()) {
            // The window in which someone can act before logins break.
            $this->logger->warning('OIDC client secret expires soon', $expiry->toArray());
        }
    }
}

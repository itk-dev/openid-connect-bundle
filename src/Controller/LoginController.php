<?php

namespace ItkDev\OpenIdConnectBundle\Controller;

use ItkDev\OpenIdConnect\Exception\OpenIdConnectExceptionInterface;
use ItkDev\OpenIdConnectBundle\Exception\InvalidProviderException;
use ItkDev\OpenIdConnectBundle\Security\OpenIdConfigurationProviderManager;
use ItkDev\OpenIdConnectBundle\Security\OpenIdLoginAuthenticator;
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
    /**
     * Query parameter naming where to go after a successful login.
     */
    public const string TARGET_PATH_PARAMETER = 'target_path';

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

        $this->rememberNamedTargetPath($request, $session);

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
     * Remember a return target named on the login link.
     *
     * For a login link followed from a public page: the firewall saves nothing,
     * because nothing was denied, so there is no requested page for
     * `createTargetPathRedirect()` to return to. A link may name one instead.
     *
     * Anything not plainly a path within this application is dropped rather than
     * corrected. This value ends up in a `Location` header after a successful login,
     * so a permissive reading turns the login route into an open redirect for anyone
     * who can get a user to follow a link.
     */
    private function rememberNamedTargetPath(Request $request, SessionInterface $session): void
    {
        $target = $request->query->get(self::TARGET_PATH_PARAMETER);

        if (null === $target) {
            // A target from an abandoned login link would otherwise sit in the session
            // and be spent by whatever login came next.
            $session->remove(OpenIdLoginAuthenticator::TARGET_PATH_SESSION_KEY);

            return;
        }

        if (!self::isLocalPath($target)) {
            $this->logger->warning('OIDC login: ignoring an unusable target_path', [
                'target_path' => $target,
            ]);

            return;
        }

        $session->set(OpenIdLoginAuthenticator::TARGET_PATH_SESSION_KEY, $target);
    }

    /**
     * Whether a value is a path into this application and nothing else.
     *
     * Rejected, in order: anything not starting with a single `/` (absolute URLs,
     * scheme-relative `//host`, bare words); a backslash anywhere, since browsers
     * have historically read `/\host` as scheme-relative; a scheme separator
     * anywhere; and control characters, which belong to header-splitting attempts.
     */
    private static function isLocalPath(string $target): bool
    {
        if (!str_starts_with($target, '/') || str_starts_with($target, '//')) {
            return false;
        }

        if (str_contains($target, '\\') || str_contains($target, '://')) {
            return false;
        }

        return 1 !== preg_match('/[\x00-\x1F\x7F]/', $target);
    }

    /**
     * Report on the client secret's expiry without standing in the way.
     *
     * Deliberately non-fatal, even once expired. The status depends on a manually
     * maintained date, which can fall out of step with the secret it describes:
     * rotate a secret without updating `client_secret_expires_at` and the date
     * reads "expired" while the secret works perfectly. So the date is treated as
     * an indicator rather than as authority — the identity provider is what
     * actually decides whether a secret still works. These records exist so that
     * when it does stop working, the reason is already in the log rather than
     * something to be worked out afterwards.
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

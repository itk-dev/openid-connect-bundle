<?php

namespace ItkDev\OpenIdConnectBundle\Security;

use ItkDev\OpenIdConnect\Exception\OpenIdConnectExceptionInterface;
use ItkDev\OpenIdConnect\Exception\ValidationException;
use ItkDev\OpenIdConnectBundle\EventSubscriber\AuthenticationAuditSubscriber;
use ItkDev\OpenIdConnectBundle\Exception\AuthenticationFailedException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Authenticator for OpenId Connect login.
 *
 * A failed callback throws `AuthenticationFailedException`, which is not an
 * `AuthenticationException` and so is not turned back into another redirect to the
 * identity provider. Consuming applications see a 500 and can render whatever they
 * like from it; what they no longer see is an unbreakable redirect loop.
 *
 * The logger is injected through `setLogger()` rather than the constructor on
 * purpose: this class is extended by consuming applications, whose subclasses
 * call `parent::__construct($providerManager)`. Adding a constructor argument
 * would either break those subclasses or leave the logger unset for all of
 * them. Symfony calls `setLogger()` automatically on any autoconfigured service
 * whose class implements `LoggerAwareInterface`, so subclasses get logging
 * without changing their constructor.
 *
 * `setLogger()` is applied to consumer subclasses via
 * `registerForAutoconfiguration()` in the bundle extension, so a configured
 * `logging_options.logger` reaches them without any wiring on their side.
 *
 * Severity is fixed per failure mode and is not configurable: the bundle decides
 * how serious an event is, the application decides which levels it keeps.
 *
 * `LoggerAwareTrait` is deliberately not used: its `$logger` property is
 * nullable, which would force a null check at every call site and leave the
 * class with two states where only one is meaningful. Defaulting to a
 * `NullLogger` keeps a single code path for consumers who never get a logger.
 */
abstract class OpenIdLoginAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface, LoggerAwareInterface
{
    use TargetPathTrait;

    /**
     * Where `LoginController` puts a target named on the login link itself.
     *
     * Not `TargetPathTrait`'s key, which is per firewall: the controller has no
     * firewall name, and inventing one to write into Symfony's slot would put a value
     * there that the firewall never saved.
     */
    public const string TARGET_PATH_SESSION_KEY = '_itkdev_oidc.target_path';

    private LoggerInterface $logger;

    /**
     * OpenIdLoginAuthenticator constructor.
     */
    public function __construct(
        private readonly OpenIdConfigurationProviderManager $providerManager,
    ) {
        $this->logger = new NullLogger();
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Whether this request is a callback for one of this authenticator's providers.
     *
     * `state` and `code` alone used to be enough, which made every URL under the
     * firewall a callback: anyone could turn any page into a failed login, and since
     * the bundle fails closed that means a 500 raised by an unauthenticated caller.
     * Requiring the configured callback path as well leaves a forged callback to the
     * firewall's ordinary handling.
     *
     * Nothing here touches the session. This runs on every request through the
     * firewall, so starting a session for anonymous traffic would be a real cost, and
     * "is this a callback" must not depend on whether this browser began the login.
     * The session's provider key is still what decides which provider validates it,
     * in `validateClaims()`.
     */
    public function supports(Request $request): ?bool
    {
        if (!$request->query->has('state') || !$request->query->has('code')) {
            return false;
        }

        // Base URL included: getPathInfo() has any subdirectory base path and trusted
        // proxy prefix stripped out, while the configured paths contain them. See
        // OpenIdConfigurationProviderManager::isCallbackPath().
        $path = $request->getBaseUrl().$request->getPathInfo();

        foreach ($this->getSupportedProviderKeys() as $providerKey) {
            if ($this->providerManager->isCallbackPath($path, $providerKey)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Redirect to the page the user originally asked for.
     *
     * Symfony saves that page when the entry point fires, which covers both shapes of
     * consumer: one that redirects straight to the identity provider, and one that
     * shows a login screen carrying a link to it. `$fallbackUrl` is for a user who
     * reached the login link without being sent there — nothing was saved then.
     *
     * The saved path is cleared on use, so a later visit to the login link does not
     * replay a stale target.
     */
    protected function createTargetPathRedirect(Request $request, string $firewallName, string $fallbackUrl): RedirectResponse
    {
        $session = $request->getSession();

        // The firewall's record first: it is the page the user was actually denied.
        $targetPath = $this->getTargetPath($session, $firewallName);

        if (null !== $targetPath && '' !== $targetPath) {
            $this->removeTargetPath($session, $firewallName);
            $session->remove(self::TARGET_PATH_SESSION_KEY);

            return new RedirectResponse($targetPath);
        }

        // Then a target named on the login link, for a user who was never denied
        // anything — they followed a login link from a public page.
        $named = $session->get(self::TARGET_PATH_SESSION_KEY);
        $session->remove(self::TARGET_PATH_SESSION_KEY);

        if (is_string($named) && '' !== $named) {
            return new RedirectResponse($named);
        }

        return new RedirectResponse($fallbackUrl);
    }

    /**
     * Provider keys whose callbacks this authenticator answers.
     *
     * Every configured provider by default, which is what keeps several
     * `OpenIdLoginAuthenticator` subclasses on one firewall working as they do
     * today: each supports every callback path, Symfony asks them in the order
     * `security.yaml` lists them, and the session's provider key decides which
     * provider validates the callback.
     *
     * Override in a subclass bound to particular providers so that, with a distinct
     * callback path per provider, each callback is answered by the authenticator that
     * owns it.
     *
     * @return string[]
     */
    protected function getSupportedProviderKeys(): array
    {
        return $this->providerManager->getProviderKeys();
    }

    /**
     * Validate oidc claims.
     *
     * @return array<string, string> Array of claims
     *
     * @throws OpenIdConnectExceptionInterface
     */
    protected function validateClaims(Request $request): array
    {
        $session = $request->getSession();
        $providerKey = $session->remove('oauth2provider');
        $providerKey = is_string($providerKey) ? $providerKey : '';

        // The session entry is removed above, so carry the provider key on the
        // request for anything downstream that needs to attribute this login —
        // the audit subscriber in particular, which sees only the security event.
        $request->attributes->set(AuthenticationAuditSubscriber::PROVIDER_ATTRIBUTE, $providerKey);

        try {
            $provider = $this->providerManager->getProvider($providerKey);
        } catch (OpenIdConnectExceptionInterface $exception) {
            // A callback whose session lost (or never had) the provider key
            // would otherwise fail here with nothing recorded. Rethrown
            // unchanged — this only adds the log line.
            $this->logger->error('OIDC login failed: provider not configured', [
                'provider' => $providerKey,
                'exception' => $exception,
            ]);

            throw $exception;
        }

        // Make sure state and oauth2state are the same
        $oauth2state = $session->remove('oauth2state');

        if ($request->query->get('state') !== $oauth2state) {
            $this->logger->warning('OIDC login failed: invalid state', ['provider' => $providerKey]);

            throw new ValidationException('Invalid state');
        }

        $oauth2nonce = $session->remove('oauth2nonce');
        if (!is_string($oauth2nonce) || '' === $oauth2nonce) {
            $this->logger->warning('OIDC login failed: nonce empty or not found', ['provider' => $providerKey]);

            throw new ValidationException('Nonce empty or not found');
        }

        try {
            $code = $request->query->get('code');

            if (!is_string($code)) {
                throw new ValidationException('Missing or invalid code');
            }

            $idToken = $provider->getIdToken($code);
            $claims = $provider->validateIdToken($idToken, $oauth2nonce);
            // Authentication successful
        } catch (OpenIdConnectExceptionInterface $exception) {
            // Handle failed authentication. This is the path an expired client
            // secret takes: the token exchange fails and only the provider's
            // message says why.
            $this->logger->error('OIDC login failed validating the authorization code', [
                'provider' => $providerKey,
                'exception' => $exception,
            ]);

            throw new ValidationException($exception->getMessage(), previous: $exception);
        }

        /** @var array<string, string> $claimsArray */
        $claimsArray = (array) $claims;

        return $claimsArray + ['open_id_connect_provider' => $providerKey];
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        // Not an AuthenticationException, and that is the entire point. The
        // security component catches those and hands control back to this
        // authenticator's own start(), which redirects to the identity provider
        // again — so a callback that keeps failing keeps being retried. That is the
        // loop that took sites.itkdev.dk down for the duration of an expired client
        // secret. AuthenticatorManager::executeAuthenticator() catches only
        // AuthenticationException, so this propagates to HttpKernel instead and the
        // application renders its own error.
        //
        // Still not logged here: AuthenticatorManager has already logged the
        // original exception, validateClaims() logged the specific reason, and the
        // application's error handling logs whatever escapes. A record here would be
        // the fourth for one failure.
        throw new AuthenticationFailedException(sprintf('Error occurred validating openid login: %s', $exception->getMessage()), $exception->getCode(), self::causeOutsideSecurity($exception));
    }

    /**
     * The first cause carrying no `AuthenticationException` anywhere beneath it.
     *
     * Changing the thrown type is not enough on its own: the security
     * `ExceptionListener` walks the whole `$previous` chain, so chaining the
     * `AuthenticationException` it handed us would put one back within reach and it
     * would redirect to the entry point regardless — the loop restored by the cause
     * instead of by the type. The library exception underneath carries the reason
     * worth keeping, and `validateClaims()` has already logged it with the full
     * chain attached.
     */
    private static function causeOutsideSecurity(\Throwable $exception): ?\Throwable
    {
        for ($cause = $exception->getPrevious(); null !== $cause; $cause = $cause->getPrevious()) {
            if (!self::containsSecurityException($cause)) {
                return $cause;
            }
        }

        return null;
    }

    private static function containsSecurityException(\Throwable $exception): bool
    {
        for ($current = $exception; null !== $current; $current = $current->getPrevious()) {
            if ($current instanceof AuthenticationException) {
                return true;
            }
        }

        return false;
    }
}

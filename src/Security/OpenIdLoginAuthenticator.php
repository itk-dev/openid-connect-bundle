<?php

namespace ItkDev\OpenIdConnectBundle\Security;

use ItkDev\OpenIdConnect\Exception\OpenIdConnectExceptionInterface;
use ItkDev\OpenIdConnect\Exception\ValidationException;
use ItkDev\OpenIdConnectBundle\EventSubscriber\AuthenticationAuditSubscriber;
use ItkDev\OpenIdConnectBundle\Exception\AuthenticationFailedException;
use ItkDev\OpenIdConnectBundle\Exception\ProviderErrorException;
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
 * identity provider. Consuming applications see an error they can render whatever
 * they like from — a 403 where the provider refused, a 500 otherwise; what they no
 * longer see is an unbreakable redirect loop.
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

    /**
     * The cap on provider-supplied text, in characters.
     *
     * Long enough for a real `error_description` — Azure AD B2C's run to a line or
     * two — and short enough that a log pipeline cannot be filled with somebody
     * else's prose.
     */
    private const int PROVIDER_TEXT_MAX_LENGTH = 200;

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
     * `error` is the other half of RFC 6749 §4.1.2.1: a provider that refuses
     * redirects back with `error` and `state` and no `code` at all. Left
     * unrecognised, that request falls to the firewall, the firewall calls this
     * authenticator's entry point, the entry point asks the provider again, and the
     * provider refuses again — a loop with no failing callback anywhere in it to log.
     *
     * `has()`, deliberately, rather than a test for a non-empty value: `?state=…&error=`
     * has to be recognised too. If it is not, the entry point fires, mints a fresh
     * state, and the next refusal arrives with a state that matches — a loop that
     * cannot even be told apart from a first attempt.
     *
     * Nothing here touches the session. This runs on every request through the
     * firewall, so starting a session for anonymous traffic would be a real cost, and
     * "is this a callback" must not depend on whether this browser began the login.
     * The session's provider key is still what decides which provider validates it,
     * in `validateClaims()`.
     */
    public function supports(Request $request): ?bool
    {
        if (!$request->query->has('state') || (!$request->query->has('code') && !$request->query->has('error'))) {
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

        // Every one-time value is spent here, before anything below can throw. A
        // callback is used up whether it succeeds, fails validation, or carries the
        // provider's refusal, and a value left behind is one a later request can
        // replay.
        $providerKey = $session->remove('oauth2provider');
        $providerKey = is_string($providerKey) ? $providerKey : '';
        $oauth2state = $session->remove('oauth2state');
        $oauth2nonce = $session->remove('oauth2nonce');

        // The session entry is removed above, so carry the provider key on the
        // request for anything downstream that needs to attribute this login —
        // the audit subscriber in particular, which sees only the security event.
        $request->attributes->set(AuthenticationAuditSubscriber::PROVIDER_ATTRIBUTE, $providerKey);

        // Read as an array rather than through InputBag::get(): `?state[]=x` makes
        // that throw Symfony's BadRequestException, and a method whose whole job is
        // to end a callback cleanly should not be the place a framework exception
        // escapes from. A non-string simply fails the comparison below.
        $query = $request->query->all();
        $state = $query['state'] ?? null;

        // Make sure state and oauth2state are the same. First, and before anything
        // else in this URL is read: until the state matches, none of it is known to
        // belong to a login this browser started, and the rest is whatever the
        // sender chose to put there.
        if (!is_string($oauth2state) || '' === $oauth2state || !is_string($state) || !hash_equals($oauth2state, $state)) {
            $this->logger->warning('OIDC login failed: invalid state', ['provider' => $providerKey]);

            throw new ValidationException('Invalid state');
        }

        // RFC 6749 §4.1.2.1: a refusal comes back as `error` with no `code`. Handled
        // here rather than left to the token exchange, which would report a missing
        // code and drop the only thing that says why the login did not happen.
        $error = self::sanitizeProviderText($query['error'] ?? null);

        if (null !== $error) {
            $errorDescription = self::sanitizeProviderText($query['error_description'] ?? null);

            // Warning, not error: much the commonest cause is a user who decided
            // not to log in, and there is nothing for an operator to fix. The
            // status code on the exception carries the distinction that matters.
            $this->logger->warning('OIDC login failed: the identity provider refused the request', [
                'provider' => $providerKey,
                'error' => $error,
                'error_description' => $errorDescription,
            ]);

            throw new ProviderErrorException($error, $errorDescription);
        }

        if (!is_string($oauth2nonce) || '' === $oauth2nonce) {
            $this->logger->warning('OIDC login failed: nonce empty or not found', ['provider' => $providerKey]);

            throw new ValidationException('Nonce empty or not found');
        }

        try {
            // Built last, and only for a callback that has passed every check that
            // costs nothing: constructing a provider pulls in discovery, an HTTP
            // client and a cache pool, none of which a refusal has any use for.
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
        $cause = self::causeOutsideSecurity($exception);

        // A provider that refused already says why, and in what terms the
        // application should answer. Wrapping it would replace a 403 the user
        // caused by clicking Cancel with a 500 somebody gets paged for. It is
        // already outside the security hierarchy and carries nothing beneath it,
        // which is exactly what a type leaving here has to be.
        if ($cause instanceof ProviderErrorException) {
            throw $cause;
        }

        throw new AuthenticationFailedException(sprintf('Error occurred validating openid login: %s', $exception->getMessage()), $exception->getCode(), $cause);
    }

    /**
     * Provider-supplied text, made fit to appear in a log record.
     *
     * `error` and `error_description` are chosen by whoever built the callback URL:
     * the identity provider on a good day, anyone who can get a browser to load a
     * URL on a bad one. So the text is treated as input rather than as a message.
     * Control characters go, because a newline is a forged second log record and an
     * escape sequence is a command to whichever terminal the log is read in; text
     * that is not valid UTF-8 goes, because the first JSON formatter to meet it
     * throws and replaces a legible failure with an illegible one; what is left is
     * capped.
     *
     * Null for anything unusable — absent, empty, an array (`?error[]=x`), or
     * nothing but control characters — so the caller can tell "no error" from "an
     * error with nothing in it" in one check.
     */
    private static function sanitizeProviderText(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        // A run of control characters becomes one space rather than nothing, so a
        // two-line description reads as two words and not as one. No /u here: this
        // is a byte-level strip that has to work on input that is not valid UTF-8.
        $text = trim(preg_replace('/[[:cntrl:]]+/', ' ', $value) ?? '');

        // Capped in characters, not bytes: a byte cap can cut a multi-byte character
        // in half, and half a character is exactly the invalid UTF-8 this is trying
        // not to produce. `.` under /u is one code point, and the pattern is
        // anchored and greedy, so this is the first N of them.
        //
        // The /u carries the UTF-8 validity check too: given input that is not valid
        // UTF-8 the match fails outright and leaves $matches empty, which is the
        // same "unusable" answer. That matters because the first JSON formatter to
        // meet invalid UTF-8 throws, and replaces a legible failure with an
        // illegible one. An empty $text matches empty and comes out null the same
        // way, so there is nothing to check for separately.
        $matches = [];
        preg_match('/^.{0,'.self::PROVIDER_TEXT_MAX_LENGTH.'}/u', $text, $matches);
        $capped = $matches[0] ?? '';

        return '' === $capped ? null : $capped;
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

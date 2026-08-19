<?php

namespace ItkDev\OpenIdConnectBundle\EventSubscriber;

use ItkDev\OpenIdConnectBundle\Log\AuthenticationAuditLogger;
use ItkDev\OpenIdConnectBundle\Security\CliLoginTokenAuthenticator;
use ItkDev\OpenIdConnectBundle\Security\OpenIdLoginAuthenticator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Turns Symfony's security events into this bundle's audit trail.
 *
 * A subscriber rather than call sites in the authenticators, because the events
 * are the authoritative moment a session was or was not established — and they
 * also cover failures thrown by a consumer's own `authenticate()`, which the
 * bundle never sees. Both events reach a plain subscriber on the global
 * dispatcher: `RegisterGlobalSecurityEventListenersPass` copies global listeners
 * onto the firewall-scoped dispatchers, and both events are in its bubbling list.
 *
 * Both events fire for **every** authenticator in the application — form logins,
 * JSON logins, remember-me, access tokens — so the first thing each handler does
 * is establish whether one of this bundle's authenticators produced it, and return
 * if not. The trail deliberately covers only logins that went through this bundle:
 * an OIDC bundle silently recording an application's password logins would extend
 * the personal-data processing past what the operator opted into, and the
 * `provider` field would be meaningless for them.
 *
 * The extension drops this subscriber entirely when `audit_options.enabled` is a
 * literal false. It cannot do that when the setting comes from an environment
 * variable — at compile time it sees an unresolved placeholder — so each handler
 * also checks first and returns before reading anything off the event. Either way,
 * a deployment that has not opted in assembles no personal data.
 */
class AuthenticationAuditSubscriber implements EventSubscriberInterface
{
    /**
     * Set by `OpenIdLoginAuthenticator::validateClaims()`.
     *
     * The OIDC provider key is otherwise unreachable from here: it lives as a
     * local in `validateClaims()`, is handed to the consumer's subclass (which
     * typically keeps only the email), and its session entry is removed
     * destructively, so it cannot be read back. A request attribute carries it
     * forward without changing that method's return contract.
     */
    public const string PROVIDER_ATTRIBUTE = '_itkdev_oidc_provider';

    public function __construct(
        private readonly AuthenticationAuditLogger $auditLogger,
    ) {
    }

    /**
     * @return array<class-string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
            LoginFailureEvent::class => 'onLoginFailure',
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        if (!$this->auditLogger->isEnabled()) {
            return;
        }

        // Called for every successful login in the application, including ones
        // this bundle knows nothing about — form logins, API tokens, remember-me.
        // A null method means the login came from one of those, and nothing is
        // recorded: no audit record, no personal data read from the event.
        $method = $this->method($event->getAuthenticator());
        if (null === $method) {
            return;
        }

        $this->auditLogger->loginSucceeded(
            $method,
            $event->getAuthenticator()::class,
            $event->getAuthenticatedToken()->getUserIdentifier(),
            $this->provider($event->getRequest()),
            $event->getFirewallName(),
            $event->getRequest()->getClientIp(),
        );
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        if (!$this->auditLogger->isEnabled()) {
            return;
        }

        // As above: a null method means some other authenticator rejected this
        // attempt, so it is none of this trail's business and nothing is recorded.
        $method = $this->method($event->getAuthenticator());
        if (null === $method) {
            return;
        }

        $exception = $event->getException();

        // `AuthenticatorManager` substitutes a generic `BadCredentialsException`
        // for sensitive causes and keeps the real one chained, so prefer the
        // chained message — an audit trail saying only "Bad credentials." records
        // that something failed without recording what.
        $reason = $exception->getPrevious()?->getMessage() ?? $exception->getMessage();

        $this->auditLogger->loginFailed(
            $method,
            $event->getAuthenticator()::class,
            $this->failedSubject($event),
            $this->provider($event->getRequest()),
            $event->getFirewallName(),
            $event->getRequest()->getClientIp(),
            $reason,
        );
    }

    /**
     * Which of this bundle's authenticators produced the event, if any.
     *
     * **A null return is the filter**: it means the event came from an
     * authenticator this bundle does not own, and the caller records nothing at
     * all. That is what keeps an application's password, JSON and API-token logins
     * out of an OIDC audit trail.
     *
     * `getAuthenticator()` already unwraps `TraceableAuthenticator`, so the
     * `instanceof` checks hold in the dev profiler too. Consumer subclasses of
     * `OpenIdLoginAuthenticator` match on the first arm, which is why the concrete
     * class is recorded separately.
     */
    private function method(AuthenticatorInterface $authenticator): ?string
    {
        return match (true) {
            $authenticator instanceof OpenIdLoginAuthenticator => AuthenticationAuditLogger::METHOD_OIDC,
            $authenticator instanceof CliLoginTokenAuthenticator => AuthenticationAuditLogger::METHOD_CLI_TOKEN,
            default => null,
        };
    }

    /**
     * The identifier behind a failed attempt, when one is available.
     *
     * Usually it is not: the passport is assigned only after `authenticate()`
     * returns, and this bundle's failures are raised inside it.
     */
    private function failedSubject(LoginFailureEvent $event): ?string
    {
        $passport = $event->getPassport();
        if (null === $passport) {
            return null;
        }

        return $passport->getUser()->getUserIdentifier();
    }

    private function provider(Request $request): ?string
    {
        $provider = $request->attributes->get(self::PROVIDER_ATTRIBUTE);

        return is_string($provider) ? $provider : null;
    }
}

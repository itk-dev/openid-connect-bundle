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
 * The extension only registers this subscriber when `audit_options.enabled` is
 * true, so a deployment that has not opted in does no event handling at all.
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
        $method = $this->method($event->getAuthenticator());
        if (null === $method) {
            return;
        }

        $this->auditLogger->loginSucceeded(
            $method,
            $event->getAuthenticatedToken()->getUserIdentifier(),
            $this->provider($event->getRequest()),
            $event->getFirewallName(),
            $event->getRequest()->getClientIp(),
        );
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
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
     * Doubles as the filter that keeps other firewalls' logins out of this trail.
     * `getAuthenticator()` already unwraps `TraceableAuthenticator`, so the check
     * is reliable in the dev profiler too.
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

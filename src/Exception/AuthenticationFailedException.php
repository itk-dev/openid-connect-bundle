<?php

namespace ItkDev\OpenIdConnectBundle\Exception;

/**
 * An OpenID Connect login could not be completed.
 *
 * Deliberately not a `Symfony\Component\Security\Core\Exception\AuthenticationException`.
 * The security component catches those and hands control back to the firewall's
 * entry point, which for this bundle means another redirect to the identity
 * provider — so a callback that keeps failing keeps being retried, forever. This
 * type propagates past the firewall instead, and the application renders its own
 * error. See docs/adr/002-fail-closed-on-authentication-failure.md.
 */
class AuthenticationFailedException extends \RuntimeException implements OpenIdConnectBundleExceptionInterface
{
}

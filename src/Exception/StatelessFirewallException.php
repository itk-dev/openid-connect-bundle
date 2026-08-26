<?php

namespace ItkDev\OpenIdConnectBundle\Exception;

/**
 * The OpenID Connect authenticator was used on a firewall with no session.
 *
 * The authorization code flow spans two requests: one that sends the browser to the
 * identity provider, and one that receives the callback. The state, the nonce and the
 * PKCE verifier are what tie them together, and a session is where they wait. A
 * firewall declared `stateless: true` has nowhere to keep them, so the callback can
 * never be validated.
 *
 * A `\LogicException`, because it describes a firewall that cannot work rather than a
 * login that went wrong: the fix is in `security.yaml`, not in a retry.
 */
class StatelessFirewallException extends \LogicException implements OpenIdConnectBundleExceptionInterface
{
}

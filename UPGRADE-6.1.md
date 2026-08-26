# Upgrading from 6.0 to 6.1

```sh
composer update itk-dev/openid-connect-bundle
```

A minor: nothing is required of you. Two things are worth checking.

## A refused login now ends in a page, not a loop

When the identity provider refuses an authorization request — the user closed the
consent screen, their session at the provider had expired, a tenant policy said no —
it redirects back to your callback with an `error` and no `code`.

Until 6.1 the bundle did not recognise that as a callback. The firewall answered it,
your entry point asked the provider again, the provider refused again, and the browser
never settled. Nothing was logged, because no failing callback existed to log.

Now that callback is handled: the login ends, the reason is logged at `warning`, and
the user gets a **403** — 503 where the provider reports its own trouble, 500 where the
error says the request or the client registration is wrong. If you were filtering the
loop out of your monitoring, you can stop.

Catch it if you want a friendlier page than your generic 403:

```php
use ItkDev\OpenIdConnectBundle\Exception\ProviderErrorException;

if ($exception instanceof ProviderErrorException
    && ProviderErrorException::ACCESS_DENIED === $exception->getError()) {
    // "You cancelled the sign-in — try again"
}
```

`ProviderErrorException` extends `AuthenticationFailedException`, so any `catch` you
already wrote for 6.0 keeps matching it.

**If your application has a listener that redirects 403 responses to a login page,
exclude this exception from it** — otherwise a refusal is sent straight back to the
provider that refused it, rebuilding the loop from your own side.

## Chain the cause in your authenticator

When your `authenticate()` catches a bundle exception and raises Symfony's, pass the
original as `previous`:

```php
} catch (OpenIdConnectExceptionInterface $exception) {
    throw new CustomUserMessageAuthenticationException($exception->getMessage(), previous: $exception);
}
```

The bundle reads that cause back to decide what the user is shown. Without it the
refusal arrives as a plain 500 with the reason only in the message. This was always
the documented shape; 6.1 is the first release where dropping the cause costs you
something.

## PKCE is on by default

Every authorization request now carries a PKCE challenge (RFC 7636, S256). RFC 6749
§3.1 requires an authorization server to ignore parameters it does not recognise, so a
provider that does not support PKCE behaves as it did before, and one that does gets
the extra protection with no configuration from you.

If you have an identity provider that rejects unknown parameters rather than ignoring
them, turn it off for that provider:

```yaml
openid_providers:
  legacy:
    options:
      pkce: false
```

The verifier is kept in the session under `oauth2pkce_verifier`. If your application
clears or rewrites the session between the login redirect and the callback, it must
preserve that key alongside `oauth2state` and `oauth2nonce`.

## The library requires 5.1

`itk-dev/openid-connect` `^5.1` comes with this release. Three of its changes affect
running deployments: an identity provider announcing plain-http endpoints now needs
`allow_http`, an ID token without `exp` or `iat` is rejected, and the JWKS cache key
changed so 5.0's entries are not reused. Read its changelog before deploying.

## `getProvider()` no longer returns the same instance

`OpenIdConfigurationProviderManager::getProvider()` builds a fresh provider on every
call. `league/oauth2-client` records the authorization request's `state` on the
provider, so a memoized instance carried one request's state into the next — which
matters once a process outlives a request, as under a FrankenPHP worker. The HTTP
client is kept per provider instead, so connections to the identity provider are still
reused.

Nothing to do unless you held the returned provider and relied on getting the same
object back.

See [ADR 004](docs/adr/004-handle-provider-error-callbacks.md) for the reasoning behind
the error-callback handling, and [CHANGELOG.md](CHANGELOG.md) for the rest of the
release.

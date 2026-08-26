# Upgrading from 6.0 to 6.1

```sh
composer update itk-dev/openid-connect-bundle
```

Nothing is required. Check the points below that apply to you.

## A refused login ends in an error page

A provider that refuses the authorization request — cancelled consent screen, expired
provider session, tenant policy — now ends the login instead of starting another one.
The bundle throws `ProviderErrorException` and the kernel answers 403, or 503 where the
provider reports its own trouble, 500 otherwise.

`ProviderErrorException` extends `AuthenticationFailedException`, so a `catch` written
for 6.0 still matches it. For a friendlier page than your generic 403:

```php
use ItkDev\OpenIdConnectBundle\Exception\ProviderErrorException;

if ($exception instanceof ProviderErrorException
    && ProviderErrorException::ACCESS_DENIED === $exception->getError()) {
    // "You cancelled the sign-in — try again"
}
```

If your application redirects 403 responses to a login page, exclude this exception
from that listener.

## Chain the cause in your authenticator

```php
} catch (OpenIdConnectExceptionInterface $exception) {
    throw new CustomUserMessageAuthenticationException($exception->getMessage(), previous: $exception);
}
```

Without `previous` the status above is lost and a refusal arrives as a plain 500.

## PKCE is on by default

Every authorization request carries an S256 challenge. The verifier is kept in the
session under `oauth2pkce_verifier`; preserve that key if you rewrite the session
between the login redirect and the callback.

For a provider that rejects parameters it does not recognise:

```yaml
openid_providers:
  legacy:
    options:
      pkce: false
```

## Requires `itk-dev/openid-connect` ^5.1

Three of its changes affect running deployments: a provider announcing plain-http
endpoints now needs `allow_http`, an ID token without `exp` or `iat` is rejected, and
the JWKS cache key changed. See its changelog.

## Smaller changes

- A firewall declared `stateless: true` throws `StatelessFirewallException` naming the
  setting, where Symfony's `SessionNotFoundException` used to surface as a 500.
- `getProvider()` returns a fresh provider on every call. Only matters if you held the
  returned object and relied on getting the same one back.
- `scopes` is configurable per provider and must include `openid`.
- `leeway` and `cache_duration` reject negative values while the container compiles.
- The authenticator implements `InteractiveAuthenticatorInterface`, so a completed
  login dispatches `security.interactive_login`.

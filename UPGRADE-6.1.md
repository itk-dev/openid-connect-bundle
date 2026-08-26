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

See [ADR 004](docs/adr/004-handle-provider-error-callbacks.md) for the reasoning, and
[CHANGELOG.md](CHANGELOG.md) for the rest of the release.

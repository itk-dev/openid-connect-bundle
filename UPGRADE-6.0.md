# Upgrading from 5.x to 6.0

A failed OpenID Connect callback now throws
`\ItkDev\OpenIdConnectBundle\Exception\AuthenticationFailedException` instead of
Symfony's `AuthenticationException`.

Before 6.0 the security component caught that exception and redirected to the
identity provider again, so a permanent failure such as an expired client secret
looped forever with no error page. The new exception escapes the firewall, so your
application renders it — a 500 by default.

See [ADR 002](docs/adr/002-fail-closed-on-authentication-failure.md).

## Migrate catch blocks

```diff
- } catch (\Symfony\Component\Security\Core\Exception\AuthenticationException $e) {
+ } catch (\ItkDev\OpenIdConnectBundle\Exception\OpenIdConnectBundleExceptionInterface $e) {
```

`getPrevious()` on the new exception is the underlying OpenID Connect exception,
not Symfony's `AuthenticationException`: the security listener follows the chain,
so one left there would loop again.

If you catch nothing today, no code change is needed. Check that a failed login
renders an acceptable error and that your error reporting picks it up.

## Rendering something friendlier than a 500

Listen for the exception. Do not answer with a redirect to the login route — that
reintroduces the loop.

```php
#[AsEventListener]
final class LoginFailureListener
{
    public function __construct(private Environment $twig)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if ($event->getThrowable() instanceof AuthenticationFailedException) {
            $event->setResponse(new Response($this->twig->render('login_failed.html.twig'), 503));
        }
    }
}
```

Render your own template, as above, rather than `getMessage()`. The message carries
the identity provider's error text, which the security component used to reduce to
a safe message key before anything could display it.

## `client_secret_expires_at` is now required

Every provider must declare when its client secret expires. Without it the bundle
cannot warn before an expiry takes every login down, which is what happened.

```yaml
itkdev_openid_connect:
    openid_providers:
        admin:
            options:
                client_secret_expires_at: '%env(string:ADMIN_OIDC_CLIENT_SECRET_EXPIRES_AT)%'
```

Anything `strtotime()` understands, but **quote it** — YAML reads an unquoted
`2027-01-31` as the number `1801353600`, and a non-string is rejected. A missing key
fails at compile time too:

```text
The child config "client_secret_expires_at" under
"itkdev_openid_connect.openid_providers.admin.options" must be configured
```

The value is not trusted as fact — nothing here blocks a login, and a value that
cannot be parsed is reported at `error` and treated as `unknown`. Keep it beside the
secret itself, so rotating one prompts updating the other. The 5.1 deprecation
warning for a missing date is gone with it.

## Removed exceptions

| Removed | Use instead |
| --- | --- |
| `ItkOpenIdConnectBundleException` (abstract, `@deprecated` since 5.0) | `OpenIdConnectBundleExceptionInterface` |
| `UserDoesNotExistException` | Symfony's `UserNotFoundException`, which the bundle already handles |

`UserDoesNotExistException` was thrown nowhere. If your user provider throws it,
switch to `UserNotFoundException`: `UserLoginCommand` catches that and reports the
username as unknown. Not to be confused with `UsernameDoesNotExistException`, which
stays — the CLI authenticator throws it when a token resolves to no username.

## Callbacks are only accepted on the configured callback path

A request counts as a callback when it carries `state` and `code` **and** arrives on a
provider's callback path. `?state=…&code=…` on any other URL is left to the firewall,
as it was before 6.0 — which is the point: since a failed callback now escapes as an
exception, any URL was otherwise a 500 an anonymous caller could trigger.

Every provider must declare one of `redirect_uri`, `redirect_route` or the new
`callback_path`, or the container will not compile:

```text
One of redirect_uri, redirect_route or callback_path must be set: it is how a
callback is recognised.
```

Set `callback_path` if a reverse proxy rewrites the path, so an external
`https://app.example.org/prefix/auth/callback` arrives here as `/auth/callback`:

```yaml
openid_providers:
    admin:
        options:
            redirect_uri: 'https://app.example.org/prefix/auth/callback'
            callback_path: '/auth/callback'
```

A proxy sending `X-Forwarded-Prefix` with trusted proxies configured needs none: the
path already matches. If you run one authenticator per provider, override
`getSupportedProviderKeys()` so each answers only its own callback; without it they
behave exactly as in 5.x.

## Redirecting back to the originally requested page

`createTargetPathRedirect()` returns the user to the page that sent them to log in:

```php
public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
{
    return $this->createTargetPathRedirect($request, $firewallName, $this->router->generate('dashboard'));
}
```

Optional — existing `onAuthenticationSuccess()` implementations keep working.

Only pages that exist and are access-controlled come back this way: routing runs
before security, so a link to a URL with no route is a 404 before the firewall sees
it and there is nothing to return to.

A login link on a public page can name its own destination with
`?target_path=/admin/reports`. The value must be a path within the application, or it
is dropped and logged.

## CLI login is unchanged

`CliLoginTokenAuthenticator` still throws `AuthenticationException`, so a consumed
or invalid login token still sends the user to your login page. That path has no
entry point of its own and cannot loop.

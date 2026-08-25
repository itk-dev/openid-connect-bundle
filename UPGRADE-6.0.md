# Upgrading from 5.x to 6.0

Every provider must declare where its callback arrives, a failed callback becomes an
error instead of a redirect, and a callback is only recognised on that path. Most
applications already satisfy the first, in which case there is nothing to change unless
you catch `AuthenticationException` around the callback.

```sh
composer require itk-dev/openid-connect-bundle:^6.0
```

`require`, not `update`: your `composer.json` pins a major — `^5.0` or similar — and
`composer update` will not cross it, so it would report nothing to do and leave you on
5.x wondering why none of this applies.

Coming from 4.x? Do [UPGRADE-5.0.md](UPGRADE-5.0.md) first — this guide assumes 5.x. On
that hop the library moves with the bundle, and naming the bundle alone refuses with
`the package is fixed to 4.1.2 (lock file version) by a partial update`, so name both:

```sh
composer require itk-dev/openid-connect-bundle:^5.0 itk-dev/openid-connect:^5.0
```

From 5.x to 6.0 the bundle alone is enough: the only requirement that changes is
`symfony/deprecation-contracts`, which is dropped.

## 1. Declare where each callback arrives

Every provider must set one of `redirect_uri`, `redirect_route` or the new
`callback_path`, or the container will not compile:

```text
Invalid configuration for path "itkdev_openid_connect.openid_providers.admin.options":
One of redirect_uri, redirect_route or callback_path must be set: it is how a callback
is recognised.
```

Most applications already have `redirect_uri` and need no change.

A request is treated as a callback when it carries `state` and `code` **and** arrives
on that path. `?state=…&code=…` on any other URL is left to the firewall, exactly as
before 6.0 — an anonymous visitor goes to your entry point, a logged-in one gets the
page. Without the path check, and with failures now raising an exception (step 2), any URL
in the application was a 500 an anonymous caller could trigger.

### When you need `callback_path`

Only for a reverse proxy that rewrites the path **without announcing it** — an
external `https://app.example.org/prefix/auth/callback` that arrives here as
`/auth/callback`:

```yaml
openid_providers:
    admin:
        options:
            redirect_uri: 'https://app.example.org/prefix/auth/callback'
            callback_path: '/auth/callback'
```

A subdirectory deployment, or a proxy sending `X-Forwarded-Prefix` with Symfony's
trusted proxies configured, needs none: the request path is matched as `getBaseUrl()`
plus `getPathInfo()`, so the prefix is accounted for on both sides.

### One authenticator per provider

Override `getSupportedProviderKeys()` so each answers only its own provider's
callback:

```php
protected function getSupportedProviderKeys(): array
{
    return ['admin'];
}
```

Without the override every authenticator supports every callback path and the
session's provider key decides which provider validates it — exactly as in 5.x.

## 2. A failed callback is now an error, not another redirect

`OpenIdLoginAuthenticator::onAuthenticationFailure()` throws
`\ItkDev\OpenIdConnectBundle\Exception\AuthenticationFailedException` instead of
Symfony's `AuthenticationException`. The security component used to catch the latter
and call your entry point again, so a permanent failure such as an expired client
secret looped forever with no error page. The new exception escapes the firewall and
your application renders it — a 500 by default. See
[ADR 002](docs/adr/002-fail-closed-on-authentication-failure.md).

**Most applications need no code change.** Catching library exceptions *inside*
`authenticate()` — the pattern in this README — is unaffected. What needs attention is
a `catch` of Symfony's `AuthenticationException` around the callback, or an overridden
`onAuthenticationFailure()`:

```diff
- } catch (\Symfony\Component\Security\Core\Exception\AuthenticationException $e) {
+ } catch (\ItkDev\OpenIdConnectBundle\Exception\OpenIdConnectBundleExceptionInterface $e) {
```

`getPrevious()` is the underlying OpenID Connect exception rather than the
`AuthenticationException`, because the security listener walks the chain and one left
there would loop again.

Then check that a failed login renders something acceptable and that your error
reporting picks it up.

### Rendering something friendlier than a 500

Listen for the exception, and do not answer with a redirect to the login route — that
reintroduces the loop:

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

Render your own template rather than `getMessage()`: the message carries the identity
provider's error text, which the security component used to reduce to a safe message
key before anything could display it.

## 3. Removed exceptions

| Removed | Use instead |
| --- | --- |
| `ItkOpenIdConnectBundleException` (abstract, `@deprecated` since 5.0) | `OpenIdConnectBundleExceptionInterface` |
| `UserDoesNotExistException` | Symfony's `UserNotFoundException`, which the bundle already handles |

`UserDoesNotExistException` was thrown nowhere. If your user provider throws it,
switch to `UserNotFoundException`: `UserLoginCommand` catches that and reports the
username as unknown. Not to be confused with `UsernameDoesNotExistException`, which
stays — the CLI authenticator throws it when a token resolves to no username.

`symfony/deprecation-contracts` is no longer required by the bundle. Require it
yourself if your own code calls `trigger_deprecation()`.

## Recommended: monitor client secret expiry

`client_secret_expires_at` stays optional. Set it and the bundle warns before a secret
expires; leave it unset and the provider reports `unknown` and is not monitored.

```yaml
itkdev_openid_connect:
    openid_providers:
        admin:
            options:
                client_secret_expires_at: '%env(string:ADMIN_OIDC_CLIENT_SECRET_EXPIRES_AT)%'
```

**Set it where the real secret lives** — the production secret store, or a `when@prod`
block. A date carried in a committed `.env` default is a date nobody maintains: it
reports `ok` while measuring nothing, which is worse than reporting `unknown`. That is
also why the key is not required: a required key can force a value, never a correct
one, and Symfony compiles a container per environment, so it would have to be set in
all of them.

Anything `strtotime()` understands, and **quote it**: YAML reads an unquoted
`2027-01-31` as the number `1801353600`, and a non-string is rejected while the
container compiles. A value that is set but cannot be parsed is reported at `error` and
treated as `unknown` — set-but-broken is a mistake, unset is a decision.

Nothing here blocks a login. An unmonitored provider shows up as `unknown` in
`ClientSecretExpiryChecker::getAllStatuses()`, which is where monitoring should alert
on it. The 5.1 deprecation for leaving the date unset is gone.

## Optional: return users to the page they asked for

`createTargetPathRedirect()` sends the user back to whatever sent them to log in,
falling back to a URL of your choosing:

```php
public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
{
    return $this->createTargetPathRedirect($request, $firewallName, $this->router->generate('dashboard'));
}
```

Existing `onAuthenticationSuccess()` implementations keep working unchanged.

Only pages that exist and are access-controlled come back this way: routing runs
before security, so a link to a URL with no route is a 404 before the firewall sees it
and there is nothing to return to.

A login link on a public page can name its own destination with
`?target_path=/admin/reports`. The value must be a path within the application, or it
is dropped and logged.

## Unchanged: CLI login

`CliLoginTokenAuthenticator` still throws `AuthenticationException`, so a consumed or
invalid login token still sends the user to your login page. That path has no entry
point of its own and cannot loop.

# Upgrading from 5.x to 6.0

```sh
composer require itk-dev/openid-connect-bundle:^6.0
```

`require`, not `update`: `composer update` will not cross the major your
`composer.json` pins. Coming from 4.x, do [UPGRADE-5.0.md](UPGRADE-5.0.md) first.

## Every provider must declare where its callback arrives

Set one of `redirect_uri`, `redirect_route` or `callback_path` per provider, or the
container will not compile:

```text
Invalid configuration for path "itkdev_openid_connect.openid_providers.admin.options":
One of redirect_uri, redirect_route or callback_path must be set: it is how a callback
is recognised.
```

Most applications already set `redirect_uri` and need no change.

## A callback is only recognised on that path

`?state=…&code=…` on any other URL is left to the firewall. If a reverse proxy rewrites
the path without sending `X-Forwarded-Prefix`, declare the path the application
receives:

```yaml
openid_providers:
    admin:
        options:
            redirect_uri: 'https://app.example.org/prefix/auth/callback'
            callback_path: '/auth/callback'
```

A subdirectory deployment, or a proxy sending `X-Forwarded-Prefix` with trusted proxies
configured, needs no `callback_path`.

## A failed callback throws `AuthenticationFailedException`

`OpenIdLoginAuthenticator::onAuthenticationFailure()` now throws
`\ItkDev\OpenIdConnectBundle\Exception\AuthenticationFailedException`, which is not a
Symfony `AuthenticationException` and so escapes the firewall. Your application renders
it — a 500 by default.

Nothing to do unless you catch `AuthenticationException` around the callback, or
override `onAuthenticationFailure()`:

```diff
- } catch (\Symfony\Component\Security\Core\Exception\AuthenticationException $e) {
+ } catch (\ItkDev\OpenIdConnectBundle\Exception\OpenIdConnectBundleExceptionInterface $e) {
```

`getPrevious()` is the underlying OpenID Connect exception, not the
`AuthenticationException`.

To render something friendlier than a 500, listen for the exception and set a response.
Do not redirect to the login route — that reintroduces the loop this replaced. Render a
template rather than `getMessage()`, which carries the identity provider's error text.

`CliLoginTokenAuthenticator` is unchanged.

## Removed

| Removed | Use instead |
| --- | --- |
| `ItkOpenIdConnectBundleException` | `OpenIdConnectBundleExceptionInterface` |
| `UserDoesNotExistException` | Symfony's `UserNotFoundException` |

`UsernameDoesNotExistException` stays. `symfony/deprecation-contracts` is no longer
required by the bundle; require it yourself if your own code calls
`trigger_deprecation()`.

## Also worth knowing

`client_secret_expires_at` is still optional, and the 5.1 deprecation for leaving it
unset is gone. New in 6.0: `callback_path`, `getSupportedProviderKeys()`,
`createTargetPathRedirect()` and `?target_path=` — see the
[README](README.md) and [CHANGELOG](CHANGELOG.md).

# Upgrading from 4.x to 5.0

5.0 reworks the bundle's exception hierarchy onto a marker interface and
bumps the `itk-dev/openid-connect` requirement to `^5.0`. Runtime behaviour
is unchanged — this document covers the consumer-visible API changes you'll
need to adjust catch blocks for.

See the architecture decision in
[docs/adr/001-marker-interface-exception-hierarchy.md](docs/adr/001-marker-interface-exception-hierarchy.md).

## Catch the marker interface, not the abstract

Concrete bundle exceptions no longer extend
`\ItkDev\OpenIdConnectBundle\Exception\ItkOpenIdConnectBundleException`.
Existing catches against the abstract will not match anything thrown by
5.0+ code:

```diff
- } catch (\ItkDev\OpenIdConnectBundle\Exception\ItkOpenIdConnectBundleException $e) {
+ } catch (\ItkDev\OpenIdConnectBundle\Exception\OpenIdConnectBundleExceptionInterface $e) {
```

The abstract class is kept through the 5.x line as a `@deprecated` alias
that still implements the marker; removal is scheduled for 6.0.

`OpenIdConnectBundleExceptionInterface` **extends** the upstream library's
`\ItkDev\OpenIdConnect\Exception\OpenIdConnectExceptionInterface`, so a
single catch handles failures from both packages:

```php
try {
    $claims = $this->validateClaims($request);
} catch (\ItkDev\OpenIdConnect\Exception\OpenIdConnectExceptionInterface $e) {
    // catches both library- and bundle-thrown OIDC failures
}
```

## Catch on SPL types still works

Each concrete now extends the SPL type that best fits its failure category,
so catches at the SPL level continue to match:

| Concrete | Parent | When it fires |
| --- | --- | --- |
| `InvalidProviderException` | `\InvalidArgumentException` | Unknown provider key requested from the manager |
| `UsernameDoesNotExistException` | `\InvalidArgumentException` | CLI login resolved a token to no username |
| `CacheException` | `\RuntimeException` | PSR-6 cache layer failed during CLI login token handling |
| `TokenNotFoundException` | `\RuntimeException` | CLI login token not found in the cache |
| `UserDoesNotExistException` | `\RuntimeException` | Configured user provider has no matching user |

For example, code catching the raw `\InvalidArgumentException` around a
provider lookup keeps working, because `InvalidProviderException` still
extends it:

```php
try {
    $provider = $providerManager->getProvider($key);
} catch (\InvalidArgumentException $e) { // still catches in 5.0
    // ...
}
```

## Update the dependency constraint

If your application pins the bundle, bump it to `^5.0`. The bundle now
requires `itk-dev/openid-connect:^5.0`; review that library's
[UPGRADE-5.0.md](https://github.com/itk-dev/openid-connect/blob/main/UPGRADE-5.0.md)
for its own contract changes (it reworks the same exception hierarchy and
tightens several IdP-payload validations).

## Framework-boundary exceptions are unchanged

`LoginController` still ships Symfony `HttpException` subclasses
(`NotFoundHttpException` for an unknown provider → 404,
`ServiceUnavailableHttpException` for an unreachable IdP / cache failure →
503), and authenticators still throw `AuthenticationException`. These are a
documented carve-out from the wrap-at-boundary rule and did not change in
5.0; the OIDC cause remains chained via `$previous`.

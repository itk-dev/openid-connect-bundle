# Serve stale discovery metadata when refresh fails

## Context

`OpenIdConfigurationProvider::getConfiguration()` caches the OIDC discovery document, and
`getJwtVerificationKeys()` caches the JWKS, both for `cacheDuration` seconds (bundle default: 86400).
Both write to the cache **only after a successful fetch**
(`vendor/itk-dev/openid-connect/src/Security/OpenIdConfigurationProvider.php`, `getConfiguration()` ~L487
and the JWKS branch ~L380):

```php
$item = $this->cacheItemPool->getItem($cacheKey);
if ($item->isHit()) {
    $configuration = (array) $item->get();
} else {
    $configuration = $this->fetchJsonResource($this->openIDConnectMetadataUrl);
    $item->set($configuration);
    // ...
}
```

When `fetchJsonResource()` throws, nothing is written and the exception propagates. There is no stale
fallback and no negative caching.

## Expected

A transient failure to reach the identity provider — DNS hiccup, brief network loss, IdP maintenance —
does not break login while a previously fetched, still-valid discovery document exists. At most one
outbound fetch attempt per retry interval, not one per inbound request.

## Actual

Once the cache entry expires, every request that needs provider metadata attempts its own outbound
fetch. While the IdP is unreachable, each of those requests waits out the HTTP timeout and then fails,
so a brief network problem becomes a full login outage plus a burst of outbound traffic.

Observed on an os2display staging deployment during a period of intermittent DNS latency — three
uncaught exceptions within nine seconds, one per inbound request to
`/v2/authentication/oidc/urls`:

```text
2026-08-04T11:34:26 CRITICAL Uncaught PHP Exception ... HttpException:
  "cURL error 28: Resolving timed out after 5002 milliseconds for
   https://<tenant>.b2clogin.com/<tenant>.onmicrosoft.com/v2.0/.well-known/openid-configuration?p=<policy>"
2026-08-04T11:34:29 CRITICAL (same)
2026-08-04T11:34:35 CRITICAL (same)
```

The underlying cause was a resolver that intermittently failed to answer, adding ~5s to name
resolution. That is an infrastructure fault and is being fixed separately — but the bundle turning a
few seconds of resolver latency into a login outage is the part worth addressing here.

## Suggested change

1. **Stale-if-error.** Keep the last known good discovery document and JWKS past their TTL, and fall
   back to the stale copy when a refresh fails. Discovery metadata changes rarely, so a stale copy is
   almost always still correct — and definitely better than an exception.
2. **Negative caching / backoff.** After a failed fetch, suppress further attempts for a short,
   configurable interval so a failing IdP produces one attempt per interval rather than one per
   request.
3. **Log the failure rather than only throwing.** Falling back to stale data should be visible in
   logs — this fits the PSR-3 logging surface added in `feature/psr3-logging`.

Both settings would presumably belong under the existing per-provider `options` tree in
`src/DependencyInjection/Configuration.php`, alongside `cache_duration`.

## Open questions

- **Where does the 5002 ms bound come from?** The bundle documents
  `http_client_options.timeout` with a default of `30.0`, so 5s is not the bundle default. Either the
  application sets `timeout: 5`, or the resolve phase is bounded by something else in the Guzzle/cURL
  stack. Worth confirming before choosing sensible defaults.
- **Should `connect_timeout` be exposable?** `Configuration.php` notes that league/oauth2-client only
  forwards `timeout`, `proxy` and `verify` to Guzzle. That means DNS and connect time are not
  separately boundable and eat into the total request budget. If we want to bound them, it needs a
  custom `httpClient` collaborator rather than the `http_client_options` passthrough.
- **Does the fix belong here or in `itk-dev/openid-connect`?** The caching code is in the library; the
  bundle owns the configuration surface. Likely both — library for the behaviour, bundle for the
  options. Move or split this issue as appropriate.

## Environment

- `itk-dev/openid-connect-bundle` with `itk-dev/openid-connect` ^5.0
- Symfony 6.4/7.x, PHP 8.3
- Consumer: `os2display/display-api-service` (`AuthOidcController::…` line 100)

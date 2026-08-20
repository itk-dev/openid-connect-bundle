# 003: Treat only the configured callback path as a callback

- **Created By:** Ture Gjørup
- **Date:** 2026-08-20
- **Decision Maker:** Ture Gjørup
- **Stakeholders:** Bundle consumers; operators of those applications; bundle
  maintainers
- **Status:** Accepted

## Context

`OpenIdLoginAuthenticator::supports()` matched on `state` and `code` alone, so every
URL behind the firewall was a potential callback. Before 6.0 a stray or forged
callback degraded quietly: the failure was an `AuthenticationException`, so the
firewall answered with a redirect to the entry point. [ADR 002](002-fail-closed-on-authentication-failure.md)
made that failure escape as `AuthenticationFailedException`, which turned the same
request into a 500 — raisable on any URL by an unauthenticated caller, and noise for
error reporting. Issue #63.

## Options Considered

1. **Match the provider's configured callback path (chosen).** The path comes from
   configuration the consumer already writes, and a request that is not a callback is
   left to the firewall.
2. **Require the session to hold `oauth2provider`.** No new configuration, but it
   reinstates the outage: a lost session would make the request merely
   unauthenticated, so the firewall calls the entry point, the provider returns a
   fresh `code`, and it arrives back with the session still broken — the loop of
   2026-08-12. It also conflates "is this a callback" with "did this browser start a
   login", and touching the session in `supports()` starts one for anonymous traffic.
3. **Leave it and filter in error reporting.** Moves a bundle defect into every
   consumer's monitoring configuration.

## Decision

Adopt option 1 in 6.0.0. `supports()` requires `state`, `code`, and a path matching
one of this authenticator's providers.

- **Paths come from configuration, not from a provider instance.** Building a provider
  pulls in discovery, an HTTP client and a cache pool; `supports()` runs on every
  request. `OpenIdConfigurationProviderManager::getRedirectUriPaths()` derives and
  memoizes them.
- **`callback_path` is the escape hatch** for a proxy that rewrites the path, where the
  external `redirect_uri` path is not the one the application sees.
- **`redirect_route` is generated as `ABSOLUTE_PATH`**, so host and scheme
  requirements on the route do not enter the comparison; a route whose path varies by
  host is not supported.
- **A provider must declare `redirect_uri`, `redirect_route` or `callback_path`.**
  Enforced when the container compiles. A provider with none has no callback path, and
  "matches every path" is the defect being removed.
- **`getSupportedProviderKeys()`** defaults to every provider, so existing
  multi-authenticator firewalls behave as before, and can be overridden by an
  authenticator bound to one provider.
- **Nothing is logged from `supports()`.** It runs pre-authentication on every
  request; a log call there is an amplifier for anyone sending traffic. The firewall's
  own handling is the record.

## Consequences

- A forged callback is handled by the firewall again, as it was before 6.0, without
  giving up fail-closed behaviour for real callbacks.
- Consumers behind a rewriting proxy must set `callback_path`, and every provider must
  declare a callback target. See `UPGRADE-6.0.md`.
- The callback path is now part of the bundle's contract with the identity provider:
  changing `redirect_uri` without changing the registration at the provider fails in
  the same way it always did, but changing it *only* at the provider now also stops
  callbacks being recognised.

## References

- [ADR 002](002-fail-closed-on-authentication-failure.md) — the fail-closed decision
  that made this worth fixing now
- Issue #63

## Not decided here

A login link followed from a public page has no requested page to return to, so it
lands on the application's fallback. Letting the link name its own destination
(`?target_path=`) would need the firewall name, which `LoginController` does not have,
and hard validation against open redirects. Left alone until a consumer needs it.

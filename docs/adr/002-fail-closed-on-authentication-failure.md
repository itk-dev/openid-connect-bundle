# 002: Fail closed when an OpenID Connect callback cannot be validated

- **Created By:** Ture Gjørup
- **Date:** 2026-08-19
- **Decision Maker:** Ture Gjørup
- **Stakeholders:** Bundle consumers; operators of those applications; bundle
  maintainers
- **Status:** Accepted

## Context

On 2026-08-12 an expired Azure client secret took `sites.itkdev.dk` down as an
endless redirect rather than an error: nine rounds in twenty-five seconds, no error
page, nothing logged.

`onAuthenticationFailure()` threw `AuthenticationException`. Symfony's security
`ExceptionListener` catches those and calls the firewall's entry point, which for
this authenticator redirects to the identity provider — so every failure re-entered
the flow that had just failed. A live SSO session upstream returned a fresh `code`
immediately, so nothing degraded and nothing stopped it.

`AuthenticatorManager::executeAuthenticator()` catches only
`AuthenticationException` (verified in `symfony/security-http` 6.4 and 8.1).
Anything else propagates to `HttpKernel`.

## Options Considered

1. **Throw outside the security hierarchy (chosen).** The firewall does not catch
   it, so the loop is impossible by construction and the invariant is one test
   assertion. Costs a MAJOR: consumers catching `AuthenticationException` must
   migrate, and users see a 500 instead of a redirect.
2. **Count attempts in the session.** No BC break, but it adds mutable state to a
   failure path that can itself lose the session, N is arbitrary, and the user
   still makes N pointless round trips first. Treats the symptom.
3. **Return an error `Response` from the handler.** Puts presentation in a bundle
   with no templates, and swallows the failure so error reporters never see it.

## Decision

Adopt option 1 in 6.0.0. `OpenIdLoginAuthenticator::onAuthenticationFailure()`
throws `AuthenticationFailedException` — a `\RuntimeException` implementing the
ADR 001 marker.

**The cause has to stay outside the hierarchy as well.** `ExceptionListener` walks
the whole `$previous` chain, so chaining the `AuthenticationException` it handed us
re-enters the entry point exactly as throwing one would. The bundle chains the
first cause below it instead — the library exception that says why the callback
failed — and nothing at all when the chain holds only security exceptions. A
knowing departure from ADR 001's "always pass `$previous`": the chain is kept as
far as it can be without restoring the loop, and the message carries the original
text either way.

**Scope: the OIDC authenticator only.** `CliLoginTokenAuthenticator` is not an
entry point, so its failures redirect to normal login and cannot loop: `supports()`
requires a `loginToken` the redirect does not carry. For a consumed single-use
token that is friendlier than a 500. The loop needs an authenticator whose own
entry point re-triggers it.

**Not logged here.** `AuthenticatorManager` logs the original exception,
`validateClaims()` logs the specific reason, and the application logs what escapes.

## Consequences

- The loop cannot recur. It is prevented by a type, not by configuration or a
  counter.
- Consumers catching `AuthenticationException` around the callback must switch to
  `OpenIdConnectBundleExceptionInterface`. See `UPGRADE-6.0.md`.
- `getPrevious()` is the underlying OpenID Connect exception, not the
  `AuthenticationException` the firewall raised.
- A transient identity-provider failure is now an error rather than a silent retry.
  Accepted: the bundle cannot tell transient from permanent, and retrying is what
  caused the outage.
- Applications wanting better than a 500 add an exception listener.

## References

- [ADR 001](001-marker-interface-exception-hierarchy.md) — the marker contract this
  concrete follows
- `AuthenticatorManager` and the security `ExceptionListener` in
  `symfony/security-http`

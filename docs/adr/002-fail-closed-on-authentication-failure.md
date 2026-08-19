# 002: Fail closed when an OpenID Connect callback cannot be validated

- **Created By:** Ture Gjørup
- **Date:** 2026-08-19
- **Decision Maker:** Ture Gjørup (draft — awaits team review)
- **Stakeholders:** Bundle consumers (applications whose users log in via this
  bundle); operators of those applications;
  `itk-dev/openid-connect-bundle` maintainers
- **Status:** Draft

## Context

On 2026-08-12 `sites.itkdev.dk` became unreachable. The Azure client secret had
expired, so the token exchange returned `invalid_client`. What users saw was not
an error but an endless redirect:

```text
GET /admin                        → 302 /openidconnect/login/azure_az
  → B2C authorize → Azure AD (silent SSO SUCCEEDS) → valid ?code=
GET /openid-connect/generic?state=…&code=…  → 302 /openidconnect/login/azure_az
```

Nine or more cycles in twenty-five seconds, no error page, nothing in the logs.
The IdP authenticated the user correctly every round; the bundle rejected its own
callback and started again.

The mechanism is the interaction between two pieces of Symfony:

1. `OpenIdLoginAuthenticator::onAuthenticationFailure()` throws an
   `AuthenticationException`.
2. Symfony's security `ExceptionListener` catches any `AuthenticationException`
   raised during a request and calls the firewall's *entry point* to start
   authentication. For this firewall the entry point is this same authenticator,
   whose `start()` redirects to the identity provider.

So every failure re-enters the flow that just failed. Because the IdP has a live
SSO session it answers immediately with a fresh `code`, which fails the same way.
Nothing in the cycle degrades, so nothing stops it: no backoff, no counter, no
error surface. The only signal available to an operator was that the site was
unusable.

`AuthenticatorManager::executeAuthenticator()` catches **only**
`AuthenticationException` (verified in `symfony/security-http` 6.4 and 8.1). An
exception outside that hierarchy propagates to `HttpKernel` and is rendered by the
application's own error handling.

### Drivers

- **Functional:**
  - A failure that cannot be resolved by retrying must not be retried. Nothing
    about a rejected callback improves by asking the identity provider again.
  - The failure must reach a human. A 500 with the cause in the log is
    actionable; a redirect loop is not.
  - Consumers must be able to catch it. Per
    [ADR 001](001-marker-interface-exception-hierarchy.md), that means
    implementing `OpenIdConnectBundleExceptionInterface`.
- **Non-functional:**
  - This changes an exception type thrown from a public method, so it is a
    consumer-visible break and belongs in a MAJOR release.
  - The 5.1 line already made the *cause* visible through logging. This is about
    the *behaviour*, and the two were deliberately separated so the diagnostics
    could ship without waiting for a major.

## Options Considered

1. **Throw outside the security hierarchy (proposed).** Introduce
   `AuthenticationFailedException extends \RuntimeException implements
   OpenIdConnectBundleExceptionInterface` and throw it from
   `onAuthenticationFailure()`. The firewall does not catch it, so it reaches
   `HttpKernel` and the application renders its own error page.
   - **Pros:** Removes the loop by construction rather than by counting attempts.
     One line of behaviour, no new state. Satisfies the ADR 001 marker contract,
     so a consumer can still catch every OIDC failure with one `catch`. The
     invariant is expressible as a single test assertion — the thrown type is not
     an `AuthenticationException` — which is what stops the loop from returning.
   - **Cons:** Consumers see a 500 where they previously saw a redirect. Anyone
     who caught `AuthenticationException` around this path must migrate. Requires
     a MAJOR release.

2. **Loop protection: count attempts in the session.** Keep throwing
   `AuthenticationException`, but track failures per session and stop redirecting
   after N.
   - **Pros:** No exception-type change, so no BC break.
   - **Cons:** Adds mutable session state to a failure path, and the failure modes
     that matter can lose the session (the loop began with a state-validation
     failure, and a lost session is one cause of that — the counter would be lost
     with it). Picking N is arbitrary; the user still suffers N pointless round
     trips through the identity provider first. It treats a symptom.

3. **Render an error page from the bundle.** Return a `Response` from
   `onAuthenticationFailure()` instead of throwing.
   - **Pros:** No exception type change; the user sees something intelligible.
   - **Cons:** Puts presentation in a bundle that has no templates and no opinion
     about the application's layout, and it swallows the failure — the
     application's error handling and error reporters never see it. A 500 that the
     application already knows how to render and report is better.

## Decision

**Adopt Option 1**, in **6.0.0**.

`OpenIdLoginAuthenticator::onAuthenticationFailure()` throws
`AuthenticationFailedException`, chaining the original exception as `$previous`
and preserving its message and code.

### Scope: the OIDC authenticator only

`CliLoginTokenAuthenticator` keeps throwing `AuthenticationException`, and that is
deliberate rather than an oversight. It is not an
`AuthenticationEntryPointInterface`, so its failures fall through to the
firewall's entry point — the OIDC authenticator — and redirect to normal login.
That cannot loop: `supports()` requires a `loginToken` query parameter, and the
redirect does not carry one. For a single-use CLI token that has already been
consumed, arriving at the normal login page is a better outcome than a 500.

The distinction to keep in mind is that the loop needs an authenticator whose own
entry point re-triggers it. Only the OIDC authenticator has that shape.

### Not logged at the failure handler

`AuthenticatorManager` already logs the original exception before substituting a
sanitised one, `validateClaims()` logs the specific reason on the
`openid_connect` channel, and the application's error handling logs whatever
escapes. A record here would be the fourth for one failure.

## Consequences

### Positive

- The loop cannot recur. It is prevented by the type of the exception, not by
  configuration, a counter, or an operator remembering something.
- A failing callback surfaces as an error the application already knows how to
  render, report and alert on.
- Paired with the 5.1 logging, an expired client secret now produces: a
  `warning`/`error` naming the cause at the callback, an application-level error
  for the escaping exception, and — where `client_secret_expires_at` is
  configured — a `critical` before it expires at all.

### Negative / Trade-offs

- **Consumer-visible break.** A consuming application that catches
  `AuthenticationException` around the callback no longer catches this. Migration
  is mechanical: catch `OpenIdConnectBundleExceptionInterface`, or let it become a
  500. Covered in `UPGRADE-6.0.md`.
- Users see the application's error page rather than being bounced back to login.
  For a genuinely broken configuration that is the point; for a transient IdP
  failure it is less forgiving than a retry. Judged the right trade, because the
  bundle cannot tell the two apart and the retry path is what produced the outage.
- Applications wanting something friendlier than a 500 must add an exception
  listener. Documented, but it is work pushed onto consumers.

### Follow-up Actions

- [ ] `UPGRADE-6.0.md` — consumer migration notes for the exception type
- [ ] Consider whether `CliLoginTokenAuthenticator` should gain its own entry
      point, which would make its failure handling a separate decision rather
      than an inherited one
- [ ] Revisit whether the bundle should offer an opt-in error-page listener, if
      several consumers end up writing the same one

## References

- Symfony `AuthenticatorManager::executeAuthenticator()` — catches only
  `AuthenticationException`:
  <https://github.com/symfony/security-http/blob/7.3/Authentication/AuthenticatorManager.php>
- Symfony security `ExceptionListener` — converts an `AuthenticationException`
  into a call to the firewall entry point:
  <https://github.com/symfony/security-http/blob/7.3/Firewall/ExceptionListener.php>
- [ADR 001](001-marker-interface-exception-hierarchy.md) — the marker-interface
  exception contract this new concrete follows
- Keep a Changelog: <https://keepachangelog.com/en/1.0.0/>
- Semantic Versioning 2.0.0: <https://semver.org/spec/v2.0.0.html>

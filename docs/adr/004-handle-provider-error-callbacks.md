# 004: Handle provider error callbacks

- **Created By:** Ture Gjørup
- **Date:** 2026-08-26
- **Decision Maker:** Ture Gjørup
- **Stakeholders:** Bundle consumers; operators of those applications; bundle
  maintainers
- **Status:** Accepted

## Context

[RFC 6749 §4.1.2.1](https://datatracker.ietf.org/doc/html/rfc6749#section-4.1.2.1)
gives an authorization request two possible answers. One is the callback everyone
thinks about, carrying `code` and `state`. The other is a refusal, carrying `error`
and `state` and no `code` at all: the user declined consent, the session at the
provider had expired, the tenant policy said no.

`OpenIdLoginAuthenticator::supports()` required both `state` and `code`, so a refusal
was not a callback. The firewall answered it as an ordinary unauthenticated request,
which means calling the entry point, which starts a fresh authorization request,
which the provider refuses again. Captured in production against Azure AD B2C:
dozens of rounds between the login route and the callback path, the browser never
settling. Nothing was logged, because no failing callback existed to log. The
one-time `oauth2state`, `oauth2nonce` and `oauth2provider` values were never
consumed, so each round replayed a session that was already half spent.

This is the same shape as the outage behind
[ADR 002](002-fail-closed-on-authentication-failure.md), reached through the one door
that decision did not close. ADR 002 stopped a *failing* callback from being retried;
it did not help a request that was never recognised as a callback.

## Options Considered

1. **Recognise the error callback and end it (chosen).** The request the provider
   actually sent gets an answer, the one-time values are consumed exactly as on any
   other callback, and the reason reaches the log and the page.
2. **Leave `supports()` alone and count entry-point invocations in the session.** The
   objections ADR 002 raised against a counter apply unchanged — it bounds the
   symptom rather than removing the cause, and picks an arbitrary limit. It also
   treats a refusal, which is a normal outcome, as an anomaly to be detected.
3. **Recognise it but reuse the state-mismatch failure.** Terminates the loop, but
   throws away the only thing the provider told us. An expired consent grant and a
   forged callback would be indistinguishable in the log and on the page, and the
   status would be a 500 either way.

## Decision

Adopt option 1 in 6.1.0. `supports()` returns `true` on a configured callback path
when the query carries `state` and either `code` or `error`.

- **State is checked before anything else the URL carries.** `error` and
  `error_description` are chosen by whoever built the callback URL. Only a matching
  state says the request belongs to a login this browser started, so nothing else in
  the query is read, logged, stored or repeated back until it matches.
- **All three one-time values are consumed up front**, before any check can throw. A
  callback is spent whether it succeeds, fails validation, or carries a refusal. A
  value left behind is one a later request can replay.
- **An empty `error` still counts as a callback.** `supports()` tests for the
  parameter's presence, not for a usable value. Anything else hands the request back
  to the entry point, which mints a fresh state — and the next refusal then arrives
  with a state that matches, making the loop indistinguishable from a first attempt.
- **The provider's text is sanitized before it is logged, held or shown.** Runs of
  control characters collapse to a single space, input that is not valid UTF-8 is
  dropped, and what remains is capped at 200 characters.
- **A distinct type, `ProviderErrorException`, carries it**, extending
  `AuthenticationFailedException` so that every `catch` already written against the
  bundle's login failure keeps matching. The error code is an accessor,
  `getError()`, not message text a consumer would have to search for.
- **The type answers `getStatusCode()`**, mapping a refusal to 403, a provider outage
  to 503, and everything else to 500. A user who clicked Cancel gets a page, not an
  incident.
- **`onAuthenticationFailure()` rethrows it unwrapped.** It is already outside the
  security hierarchy and carries nothing beneath it, which is what ADR 002 requires
  of anything leaving there; wrapping it would only discard the status.
- **Nothing is constructed until the cheap checks pass.** Building a provider pulls
  in discovery, an HTTP client and a cache pool, and a refusal has no use for any of
  them, so `getProvider()` now runs after the state check rather than before it.

## Consequences

The loop cannot form on this shape either, and as with
[ADR 003](003-constrain-supports-to-callback-path.md) it is prevented by a type and a
path check rather than by a counter. Refusals become visible in the log at a level
that matches who is at fault: `warning` from the bundle, and a 4xx that Symfony's
`ErrorListener` records at `error` rather than `critical`.

Accepted costs:

- The bundle now states an HTTP status from a class under `Security/` rather than
  `Controller/`. The status is metadata the kernel reads off the exception, not a
  response the bundle renders, and the alternative is paging an operator every time a
  user changes their mind.
- An application whose own listener redirects 403 responses to a login page can
  rebuild a loop for itself. That listener is the application's, not the firewall's,
  and `ProviderErrorException` is a distinct type precisely so it can be excluded.
- A callback carrying an `error` with nothing usable in it — empty, an array, or
  nothing but control characters — is reported as a missing code. It still ends the
  callback; it simply has no refusal to report.
- The reordering means a forged callback naming a provider that is no longer
  configured is now reported as an invalid state at `warning`, where it used to be
  reported as an unconfigured provider at `error`.
- The status reaches the application only if the consumer's `authenticate()` chains
  the bundle exception into the `AuthenticationException` it raises, which is what
  the documented subclass does. A consumer that drops the cause gets a 500 with the
  reason in the message.

## How much of what the provider says we repeat

`error` and `error_description` arrive in a URL. On a good day the identity provider
put them there; on a bad one, anyone who can get a browser to load a link. They are
treated as input, not as a message.

Control characters go first, because a newline in a log record forges a second
record, and an escape sequence is a command to whichever terminal someone reads the
log in. Input that is not valid UTF-8 is dropped, because the first JSON formatter to
meet it throws — replacing a legible failure with an illegible one, inside the code
that was handling a failure. What survives is capped, so no one can fill a log
pipeline with their own prose. The cap counts characters rather than bytes: cutting a
multi-byte character in half would produce exactly the invalid UTF-8 the step before
it just rejected.

The same sanitized values, and only those, reach the exception, so nothing raw
crosses the bundle's public surface. And none of it is read at all until the state
matches.

## References

- [RFC 6749 §4.1.2.1](https://datatracker.ietf.org/doc/html/rfc6749#section-4.1.2.1) —
  the error response, and [§10.12](https://datatracker.ietf.org/doc/html/rfc6749#section-10.12)
  for what `state` is for
- [OpenID Connect Core 1.0 §3.1.2.6](https://openid.net/specs/openid-connect-core-1_0.html#AuthError) —
  authentication error response
- [OAuth 2.0 Security Best Current Practice](https://datatracker.ietf.org/doc/html/draft-ietf-oauth-security-topics) —
  on what may be trusted in callback parameters
- [OWASP Logging Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html) —
  log injection via untrusted text
- [Symfony: `HttpExceptionInterface` and error handling](https://symfony.com/doc/current/controller/error_pages.html),
  and [`framework.exceptions`](https://symfony.com/doc/current/reference/configuration/framework.html#exceptions)
  for pinning a status and log level per exception class
- [Microsoft Entra External ID error codes](https://learn.microsoft.com/en-us/azure/active-directory-b2c/error-codes) —
  the vendor codes that make the `default` arm of the status mapping necessary
- [ADR 002](002-fail-closed-on-authentication-failure.md) — the fail-closed decision
  this preserves
- [ADR 003](003-constrain-supports-to-callback-path.md) — the path constraint that
  keeps the widened `supports()` from reopening issue #63

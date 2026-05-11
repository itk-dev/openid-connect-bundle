# 001: Adopt marker-interface exception hierarchy across library and bundle

- **Created By:** Ture Gjørup
- **Date:** 2026-05-11
- **Decision Maker:** Ture Gjørup (draft — awaits team review)
- **Stakeholders:** Bundle consumers (downstream apps catching OIDC exceptions);
  `itk-dev/openid-connect` library maintainers;
  `itk-dev/openid-connect-bundle` maintainers
- **Status:** Draft

## Context

The principles this ADR proposes — cross-package marker interfaces,
concrete exceptions extending SPL types, wrap-at-boundary discipline with
`$previous` chained — are the intended design of the bundle's and
library's exception layer. This is not a change in design direction. It
is a course correction: the implementation has drifted from the intent on
several specific points, and the migration below closes those gaps and
adds the static-analysis guardrails needed to keep the implementation
aligned going forward.

This bundle and its upstream `itk-dev/openid-connect` library expose
exception types that consuming applications catch in production code. The
shape of that exception hierarchy is part of the public API: a consumer
deciding whether to retry, re-authenticate, or fail hard has to be able to
distinguish failure modes by `catch` type. Today, the hierarchy makes
that decision harder than it needs to be.

**Library — `itk-dev/openid-connect`.**
The base type is the abstract class
`\ItkDev\OpenIdConnect\Exception\ItkOpenIdConnectException`, which extends
`\Exception` directly. Every concrete exception (`CacheException`,
`HttpException`, `JsonException`, `ValidationException`, `ClaimsException`,
`CodeException`, `BadUrlException`, `IllegalSchemeException`,
`MissingParameterException`, `NegativeLeewayException`,
`NegativeCacheDurationException`, `DecodeException`, `KeyException`)
extends that abstract.

**Bundle — `itk-dev/openid-connect-bundle`.**
The base type is a separate abstract class
`\ItkDev\OpenIdConnectBundle\Exception\ItkOpenIdConnectBundleException`,
also extending `\Exception` directly. Concrete bundle exceptions
(`CacheException`, `InvalidProviderException`, `TokenNotFoundException`,
`UsernameDoesNotExistException`, `UserDoesNotExistException`) extend that
abstract. **There is no inheritance link between the bundle's base and
the library's** — they are two parallel trees that happen to share a
domain.

This produces three consumer-visible problems:

1. **No cross-package catch.** A downstream application that needs to
   handle "any OIDC failure" — for example, to render a generic
   re-authentication page — cannot write a single `catch` block.
   `catch (ItkOpenIdConnectBundleException $e)` misses everything the
   library throws (`HttpException` from the IdP discovery fetch,
   `ClaimsException` from token validation, …). `catch (\Exception $e)`
   is too wide and matches unrelated failures. The only correct option is
   a list of every concrete, which rots as the libraries evolve.

2. **No SPL-typed semantics.** The concretes all extend the bundle/library
   abstract, which extends `\Exception`. A generic retry decorator that
   does `catch (\RuntimeException $e)` to retry transient failures, or a
   strict input validator that catches `\InvalidArgumentException` — both
   common idioms — does not match anything in the OIDC trees, because the
   trees skip the SPL middle layer.

3. **Lost cause chain.** Eight wrap sites across the bundle and library
   catch a dependency exception, copy its message into a new bundle/library
   exception, and discard `$previous`. Log traversal stops at the wrapper
   — the originating Guzzle / PSR-cache / lcobucci-JWT exception is
   invisible. Concretely: four sites in
   `src/Util/CliLoginHelper.php` (PSR cache wraps),
   `src/Security/OpenIdLoginAuthenticator.php` line 75 (library exception
   wrap), and three sites in the upstream library's
   `OpenIdConfigurationProvider` (JWKS fetch, JWT validation, JSON
   resource fetch).

There are two related defects that the same migration should fix:

- `src/Security/OpenIdLoginAuthenticator::validateClaims` calls
  `$this->providerManager->getProvider($providerKey)` (line 49) without a
  surrounding `try`/`catch`. The upstream `@throws` declares
  `ItkOpenIdConnectException`, which escapes uncaught from a `protected`
  method on a class designed to be subclassed.
- Upstream `OpenIdConfigurationProvider::__construct` throws a raw
  `\InvalidArgumentException` (not within the library's hierarchy);
  `checkResponse` lets
  `League\OAuth2\Client\Provider\Exception\IdentityProviderException`
  escape unwrapped through `getIdToken`. Both are boundary leaks — a
  dependency's type surfaces from a public method of ours.

The gap surfaced concretely during review of
[PR #33](https://github.com/itk-dev/openid-connect-bundle/pull/33)
(LoginController HTTP error mapping): the reviewer flagged that the
controller's `catch` list was enumerating subtypes incompletely.
Widening to the documented base addressed the immediate question, but the
underlying contract problem — that the base isn't part of a usable
cross-package hierarchy — remained.

### Drivers

- **Functional:**
  - A consumer must be able to catch "any OIDC failure" with a single
    `catch` line. This is the conventional Symfony / PSR idiom: every
    Symfony component ships an `ExceptionInterface` marker
    (`Symfony\Component\Security\Core\Exception\ExceptionInterface`,
    `Symfony\Component\Console\Exception\ExceptionInterface`, etc.);
    PSR-18 ships
    `Psr\Http\Client\ClientExceptionInterface`.
  - The `$previous` chain is consumer-visible debugging information and
    must be preserved. Symfony's `Psr18Client` is the canonical wrap-at-
    boundary reference: it translates `TransportExceptionInterface` into
    PSR-18 exceptions while passing the original as `$previous`.
  - The HTTP controller already exits through Symfony's `HttpException`
    subclasses (per PR #33). The contract needs to declare this as a
    deliberate carve-out rather than treat it as a violation.

- **Non-functional:**
  - Maintainability: the current convention is informal — nothing
    prevents a future throw site from regressing (a new exception that
    doesn't fit the tree, a wrap site that drops `$previous`). Static
    analysis can lock the contract on every CI run.
  - Migration cost: any change to the public exception hierarchy is
    consumer-visible. The migration's cost is dominated by downstream
    apps mechanically rewriting `catch` blocks; the bundle's internal
    refactor is small.
  - Release coordination: the bundle depends on the library
    (`"itk-dev/openid-connect": "^4.0"` in `composer.json`). A
    contract-level change to the library forces a coordinated MAJOR cut
    on both packages — composer cannot resolve the new bundle against
    the old library.
  - Exception granularity: PSR-18's meta document
    ([psr-18 meta](https://www.php-fig.org/psr/psr-18/meta/)) explicitly
    rejected adding subtypes like `TimeOutException` because consumers
    would handle them identically. Target: three to five concrete
    failure categories per package, not one per RFC section.

### Options Considered

1. **Marker interface + SPL re-parenting (proposed).** Introduce two
   marker interfaces:
   `\ItkDev\OpenIdConnect\Exception\OpenIdConnectExceptionInterface`
   (in the library) and
   `\ItkDev\OpenIdConnectBundle\Exception\OpenIdConnectBundleExceptionInterface`
   (in the bundle, extending the library's). Re-parent every concrete to
   its SPL base (`\RuntimeException` for transient/network/data,
   `\LogicException` for programmer/config bugs,
   `\InvalidArgumentException` for invalid input). Each concrete
   implements the appropriate marker. Keep the existing abstract base
   classes as `@deprecated` aliases that implement the marker, so
   `catch (ItkOpenIdConnectBundleException $e)` keeps working through
   the 5.x line. Coordinated 5.0 majors on both packages. Custom PHPStan
   rules in the bundle lock the contract on every CI run.
   - **Pros:** Single `catch` line covers both packages. SPL parents
     give the rare consumer who catches on `\RuntimeException` correct
     semantics. PHPStan-enforced so the contract cannot silently rot.
     `$previous` chain becomes a tested invariant. The
     marker-interface idiom matches Symfony and PSR conventions.
   - **Cons:** Two coordinated MAJOR releases. Consumers must migrate
     `catch` blocks at upgrade time (mechanical: catch on the marker
     interface rather than the abstract; class names of concretes
     unchanged). ~200 lines of in-tree PHPStan rule code maintained
     alongside production code.

2. **Marker interface only, no SPL re-parenting.** Add the marker
   interface and have the existing abstract base classes implement it.
   Concrete exceptions keep their current parent (the abstract).
   Additive — no MAJOR bump needed.
   - **Pros:** No BC break, no MAJOR cut, consumers can adopt at their
     own pace. The cross-package catch problem is solved (the most
     visible defect).
   - **Cons:** Skips the SPL hierarchy entirely. The
     catch-on-`\RuntimeException` idiom never becomes available. Future
     maintainers see the abstract being both "deprecated, prefer the
     marker" and "still the only path to concrete subtypes" — confusing.
     The implementation is half a design.

3. **Status quo + targeted bug fixes only.** Keep the current abstract-
   class convention, but fix the discrete defects (`$previous` chains,
   the missing `getProvider` catch in `validateClaims`, the library
   boundary leaks). No public API change.
   - **Pros:** Lowest-cost option. PATCH-level releases only. No
     consumer migration. The most visible bugs go away.
   - **Cons:** The cross-package catch problem remains unfixable
     without a public API change. The catch-on-SPL idiom remains
     unavailable. Future maintainers inherit the same gap and the
     question recurs the next time someone writes a consumer that
     needs to handle OIDC failures uniformly.

## Decision

**Adopt Option 1.** Migrate to the marker-interface contract via
coordinated 5.0 majors on the library and bundle, sequenced as four PRs:

- **PR 0** — `$previous` chaining bug fixes (4.x patch). Bundle repo,
  branch `fix/exception-cause-chain`,
  [PR #35](https://github.com/itk-dev/openid-connect-bundle/pull/35). No
  dependencies; ships on the 4.x line as a `### Fixed` bullet.
- **PR 1** — Library marker interface + SPL re-parenting + boundary
  wraps. Library repo, branch `feature/exception-contract-5.0`, tag
  `5.0.0`. No dependencies.
- **PR 2** — Bundle marker interface + re-parent + composer bump + a new
  carve-out subsection that documents the controller HTTP-exception
  exit. Bundle repo, branch `feature/exception-contract-5.0`, tag
  `5.0.0`. Depends on PR 1 tagged so composer can resolve `^5.0`.
- **PR 3** — Custom PHPStan rules locking the contract. Bundled inside
  PR 2 so a future revert of the migration also reverts the lock-in
  (intentional friction; one cannot land without the other).

PR 0 ships as part of the in-flight 4.2.0 release. PR 2's `[Unreleased]`
bullets are reserved for the section opened after 4.2 is cut. The
bundle's `composer.json` constraint moves from
`"itk-dev/openid-connect": "^4.0"` to `"^5.0"` in PR 2.

### Concrete-class parent mapping

Library concretes (PR 1):

- `\LogicException` — `BadUrlException`, `IllegalSchemeException`,
  `MissingParameterException`, and a new `ConfigurationException` that
  replaces the raw `\InvalidArgumentException` currently thrown by
  `OpenIdConfigurationProvider::__construct`.
- `\InvalidArgumentException` — `NegativeLeewayException`,
  `NegativeCacheDurationException`.
- `\RuntimeException` — `CacheException`, `HttpException` (also keeps
  `Psr\Http\Client\ClientExceptionInterface`, so PSR-18-savvy consumers
  can still catch on the standard PSR interface),
  `JsonException`, `DecodeException`, `KeyException`, `CodeException`,
  `ValidationException`, `ClaimsException`.

Bundle concretes (PR 2):

- `\InvalidArgumentException` — `InvalidProviderException`,
  `UsernameDoesNotExistException`.
- `\RuntimeException` — `CacheException`, `TokenNotFoundException`,
  `UserDoesNotExistException`.

### Boundary fixes (in scope for PR 1 and PR 2)

- `OpenIdLoginAuthenticator::validateClaims` line 49 — wrap
  `getProvider()` in `try`/`catch (OpenIdConnectExceptionInterface)`
  and surface as a bundle-typed exception with `$previous` chained.
- `OpenIdConfigurationProvider::__construct` — wrap raw
  `\InvalidArgumentException` into the new `ConfigurationException`.
- `OpenIdConfigurationProvider::checkResponse` — wrap
  `League\OAuth2\Client\Provider\Exception\IdentityProviderException`
  into `CodeException` with `$previous` at the public method that
  triggers it (`getIdToken`).
- All eight `$previous`-discarding wrap sites named in Context — pass
  the caught exception as `$previous`. PR 0 fixes the bundle's five
  sites already.

### Controller carve-out

The `LoginController::login` method exits through Symfony's
`NotFoundHttpException` / `ServiceUnavailableHttpException` (added in
PR #33) rather than through the bundle marker. The Symfony HTTP kernel
reads the status code off these specific concretes to render the
response; wrapping them into bundle types would require an exception
listener to unwrap and re-throw, with no benefit to a catching consumer
(the controller's caller is `HttpKernel`, not user code). The OIDC
cause is preserved via `$previous` so logs still carry the underlying
failure.

This carve-out is scoped to `src/Controller/` and encoded in the custom
PHPStan rule `ThrownExceptionImplementsBundleMarker` (see below).

### Static-analysis lock-in (PR 3)

Added to `require-dev`:

- `phpstan/phpstan-strict-rules` — catches general exception-hygiene
  issues (empty catches, throws from void methods).
- `phpstan/phpstan-deprecation-rules` — flags any in-tree code still
  using the deprecated abstract bases, so the team is reminded if a
  future throw site picks up the wrong parent.

Custom rules registered via `phpstan/exception-contract.neon`:

- **`ThrownExceptionImplementsBundleMarker`** — every `throw new X(...)`
  node in `src/` (excluding `src/Controller/`) must throw a class that
  implements either bundle marker or library marker. The Controller
  exception accepts any class implementing
  `\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface`.
- **`WrappedExceptionChainsPrevious`** — every `throw new Y(...)` inside
  a `catch (\Throwable $e)` block must pass `$e` as `$previous`. Escape
  hatch via per-line `@phpstan-ignore` annotation with a
  justification comment.

The PHPStan `paths` setting is extended to include `tests/`, so
`@throws` drift in test stubs and test exception expectations gets
caught too.

## Consequences

### Positive

- Consumers can write
  `catch (OpenIdConnectBundleExceptionInterface $e)` (or the library
  marker) to handle all OIDC failures with one block, instead of
  enumerating concretes or catching `\Throwable`.
- Generic retry / validator code that catches on `\RuntimeException` or
  `\InvalidArgumentException` now matches the appropriate OIDC failures.
  Today such code is invisible to bundle/library exceptions.
- `$previous` chain becomes a tested invariant; log traversal across the
  bundle → library → dependency boundary works without consumers having
  to unwrap manually.
- The contract is mechanically enforced from CI day one. A future throw
  site that picks up a non-marker exception, or a future wrap site that
  drops `$previous`, fails CI before review can miss it.
- The controller HTTP-exception carve-out is explicit, documented, and
  statically scoped — no longer an undeclared deviation.

### Negative / Trade-offs

- Two coordinated MAJOR releases. Consumers cannot install bundle 5.0
  against library ≤ 4.x — the `composer.json` constraint enforces the
  new contract.
- Consumer catch-block migration: anyone catching
  `ItkOpenIdConnectBundleException` or `ItkOpenIdConnectException` (the
  abstract bases) must switch to the marker interface. Concrete class
  names and namespaces stay stable so the migration is mechanical, not
  semantic. Upgrade notes accompany the 5.0 release.
- A `CacheException` re-parented to `\RuntimeException` may now match a
  downstream `catch (\RuntimeException $e)` that previously did not.
  Reviewed during planning: this is the desired semantic outcome, and is
  called out in the upgrade notes.
- ~200 lines of in-tree PHPStan rule code (`phpstan/Rule/`) plus rule
  fixtures (`phpstan/tests/`) maintained alongside the production code.
  Cost is one-time write + occasional adjustment when PHPStan's rule
  API evolves.
- The deprecated abstract base classes stay in the codebase through
  5.x; removal deferred to 6.0. Cost is two empty class files surviving
  one major.

### Follow-up Actions

- [x] PR 0 — `$previous`-chain bug fixes
  ([PR #35](https://github.com/itk-dev/openid-connect-bundle/pull/35))
- [ ] PR 1 — upstream library 5.0
  (`itk-dev/openid-connect`, separate repo)
- [ ] PR 2 — bundle 5.0 contract migration + carve-out subsection added
  to the project's contributor docs (depends on PR 1 tagged)
- [ ] PR 3 — custom PHPStan rules (bundled inside PR 2)
- [ ] `UPGRADE-5.0.md` (or equivalent README section) listing the
  consumer `catch`-migration steps
- [ ] Open follow-up to revisit removing the deprecated abstract base
  classes in 6.0

## References

- PSR-18 HTTP Client — `ClientExceptionInterface` /
  `NetworkExceptionInterface` / `RequestExceptionInterface`:
  <https://www.php-fig.org/psr/psr-18/>
- PSR-18 meta document — design rationale for not subtyping by failure
  mode: <https://www.php-fig.org/psr/psr-18/meta/>
- Symfony Security Core exception marker —
  `Symfony\Component\Security\Core\Exception\ExceptionInterface`:
  <https://github.com/symfony/symfony/blob/7.3/src/Symfony/Component/Security/Core/Exception/ExceptionInterface.php>
- Symfony Console exception marker —
  `Symfony\Component\Console\Exception\ExceptionInterface`:
  <https://github.com/symfony/symfony/blob/7.3/src/Symfony/Component/Console/Exception/ExceptionInterface.php>
- Symfony `Psr18Client` — reference implementation of wrap-at-boundary
  translating `TransportExceptionInterface` into PSR-18 exceptions
  while preserving `$previous`:
  <https://github.com/symfony/symfony/blob/7.3/src/Symfony/Component/HttpClient/Psr18Client.php>
- Bloch, *Effective Java*, Item 73: "Throw exceptions appropriate to
  the abstraction" — the principle behind wrap-at-boundary.
- Keep a Changelog: <https://keepachangelog.com/en/1.0.0/>
- Semantic Versioning 2.0.0: <https://semver.org/spec/v2.0.0.html>

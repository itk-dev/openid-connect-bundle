# PHPStan exception-contract rules

Two custom PHPStan rules lock the exception contract documented in
[`CLAUDE.md`](../CLAUDE.md). They run automatically as part of
`task analyze:php` (and therefore `task pr:actions`).

## `ThrownExceptionImplementsBundleMarker`

Every literal `throw new X(...)` site under `src/` must throw an exception
that implements:

- `ItkDev\OpenIdConnectBundle\Exception\OpenIdConnectBundleExceptionInterface`, or
- `ItkDev\OpenIdConnect\Exception\OpenIdConnectExceptionInterface` (the upstream marker).

Two framework-boundary carve-outs apply, matching the prose in CLAUDE.md:

| Path                                | Additionally allowed                                                    | Why                                                                |
| ----------------------------------- | ----------------------------------------------------------------------- | ------------------------------------------------------------------ |
| `src/Controller/*`                  | `Symfony\Component\HttpKernel\Exception\HttpExceptionInterface`         | Symfony's kernel renders the HTTP status off these.                |
| `src/Security/*Authenticator.php`   | `Symfony\Component\Security\Core\Exception\AuthenticationException`     | Symfony Security catches these to render the auth-failure response.|

Escape hatch for an intentional outlier:

```php
// @phpstan-ignore throw.notMarkerInterface
throw new MyOddException(...);
```

Pair the annotation with a one-line justification comment.

## `WrappedExceptionChainsPrevious`

Any `throw new Y(...)` inside a `catch (... $e)` block must pass `$e`
as the new exception's `$previous`, either as a named argument
(`previous: $e`) or as a direct positional argument value. This preserves
`getPrevious()` traversal for logs and debugging — the lesson from the
4.1 → 4.2 bug-fix PR that surfaced four `CliLoginHelper` wrap sites
discarding the original cause.

The check is intentionally lenient: it accepts any positional argument
whose value is the catch variable, not just the 3rd slot. This covers
Symfony's HTTP exception subclasses (which bake the status into the
class and place `$previous` at index 1) without needing constructor
reflection.

Escape hatch when the rethrow is intentionally unrelated to the cause:

```php
} catch (\Throwable $e) {
    // @phpstan-ignore throw.unchainedPrevious
    throw new UnrelatedDomainException('...');
}
```

Annotate with a justification.

## Verifying the rules

The rules are exercised on every CI run because PHPStan analyses `src/`
and `tests/`. If a refactor produces a contract violation, the build
breaks — which is the point. To verify a rule manually after editing,
clear the cache and re-run:

```shell
docker compose exec phpfpm vendor/bin/phpstan clear-result-cache
task analyze:php
```

## Why not php-cs-fixer

php-cs-fixer's rule model is syntactic. Exception-type enforcement is
a type-level concern — what a thrown class extends or implements — and
PHPStan has the reflection machinery to express it. Future work could
add a php-cs-fixer rule for the narrower "no `throw new \Exception(…)`
anywhere" check, but the inheritance graph belongs to PHPStan.

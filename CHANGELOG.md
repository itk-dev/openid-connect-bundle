# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html)

## [Unreleased]

## [6.1.0] - 2026-08-26

See [UPGRADE-6.1.md](UPGRADE-6.1.md). Nothing is required of a consumer.

### Added

- PKCE (RFC 7636, S256) on every authorization request. The verifier is kept in the
  session under `oauth2pkce_verifier`. `pkce: false` per provider turns it off.
- `scopes` per provider, defaulting to `openid`, `email` and `profile`. Accepts a list
  or a space-separated string, and must include `openid`.
- `ProviderErrorException`, thrown when the identity provider refuses the authorization
  request (RFC 6749 §4.1.2.1). Extends `AuthenticationFailedException` and carries
  `getError()`, `getErrorDescription()` and `getStatusCode()`. See
  [ADR 004](docs/adr/004-handle-provider-error-callbacks.md).
- `StatelessFirewallException`, thrown when the authenticator is used on a firewall
  declared `stateless: true`.
- `OpenIdConfigurationProviderManager::isPkceEnabled()` and `getScopes()`.
- README sections on developing against a mock identity provider and on running under
  a worker runtime.
- A worker-mode CI gate ([Igor](https://github.com/igor-php/igor-php)) with
  `igor-baseline.json`. `task analyze:worker` runs it locally.

### Fixed

- A callback carrying `error` and no `code` is recognised, so a refused login ends in
  an error page instead of another authorization request (#63 shape, seen against
  Azure AD B2C).

### Changed

- Requires `itk-dev/openid-connect` `^5.1`, which enforces `allowHttp` on every
  discovered endpoint, requires `exp` and `iat` on the ID token, and changes the JWKS
  cache key.
- A refused login answers 403, or 503 where the provider reports its own trouble and
  500 otherwise. Other callback failures still answer 500.
- `error` and `error_description` are sanitized before they are logged or held, and are
  not read until the callback's state matches.
- `oauth2provider`, `oauth2state`, `oauth2nonce` and `oauth2pkce_verifier` are consumed
  on every callback.
- The stored state is compared with `hash_equals()`; an empty or missing one is
  rejected explicitly.
- `getProvider()` returns a fresh provider on every call. The HTTP client is cached per
  provider instead, so connections are still reused.
- `OpenIdLoginAuthenticator` implements `InteractiveAuthenticatorInterface`, so a
  completed login dispatches `security.interactive_login`.
- `leeway` and `cache_duration` reject negative values while the container compiles.
- A callback whose state does not match is reported as an invalid state even when its
  provider key is no longer configured.

## [6.0.0] - 2026-08-25

See [UPGRADE-6.0.md](UPGRADE-6.0.md).

### Changed (BREAKING)

- A failed OpenID Connect callback throws `AuthenticationFailedException` instead of
  Symfony's `AuthenticationException`, so it escapes the firewall rather than
  redirecting to the identity provider again. `CliLoginTokenAuthenticator` is
  unchanged.
- `getPrevious()` on that exception is the underlying OpenID Connect exception.
- A callback is recognised only on the provider's callback path, not on any URL
  carrying `state` and `code` (#63).
- Each provider must declare `redirect_uri`, `redirect_route` or `callback_path`.

### Added

- `callback_path` per provider, for a proxy that rewrites the path without announcing
  it.
- `OpenIdLoginAuthenticator::getSupportedProviderKeys()`, to narrow an authenticator to
  named providers. Defaults to all of them.
- `OpenIdLoginAuthenticator::createTargetPathRedirect()`, returning the user to the
  page that sent them to log in.
- `?target_path=` on the login route, validated as a path within the application.
- `OpenIdConfigurationProviderManager::getRedirectUriPaths()` and `isCallbackPath()`.

### Changed

- `client_secret_expires_at` remains optional, and the 5.1 deprecation for leaving it
  unset is gone. Unset reports `unknown`; a value that is set must be a string and
  parseable.
- `UPGRADE-6.0.md` rewritten and linked from `README.md`.
- Static analysis runs against both ends of the supported dependency range.
- `actions/checkout` updated to v7.

### Fixed

- `logging_options.logger` no longer depends on bundle registration order.
- `getContainerExtension()` no longer returns `mixed` on Symfony 6.4.

### Removed (BREAKING)

- `ItkOpenIdConnectBundleException`. Catch `OpenIdConnectBundleExceptionInterface`.
- `UserDoesNotExistException`. Use Symfony's `UserNotFoundException`.
- `symfony/deprecation-contracts` from `require`.

## [5.1.1] - 2026-08-19

### Fixed

- `audit_options.identifier` is documented and enforced as not settable from an
  environment variable. The HMAC key is chosen while the container compiles, so an
  environment variable left it hashing with an empty key — pseudonymised in
  appearance only. Use environment-specific configuration to vary it.
- The audit subscriber returns before reading anything off a security event when
  the trail is disabled, so `audit_options.enabled` fed from an environment
  variable assembles no personal data either. Removing the subscriber outright
  remains an optimisation for a literal `false`.
- `client_secret_expires_at` can be set from an environment variable again.
  Validating it as the container compiled made Symfony reject any `%env()%`
  value, which is how every deployment supplies it. Unparseable and blank values
  are now reported at runtime: the provider is `unknown` and an `error` is logged
  saying it is not being monitored. A mistyped literal still fails the build.

## [5.1.0] - 2026-08-19

### Added

- Per-provider `client_secret_expires_at` and `secret_expiry_options.warning_days`,
  so the bundle knows when a client secret expires. Validated at compile time.
- Documented how to surface client secret expiry through an application's own
  health endpoint, using the public `ClientSecretExpiryChecker`.
- A `critical` record once a client secret is past its configured expiry, and a
  `warning` while it is expiring soon. Neither blocks a login: the identity
  provider stays the authority on whether a secret still works.
- Opt-in authentication audit trail (`audit_options`), writing logins, failed
  attempts and CLI token issuance at `info` on the `openid_connect_audit`
  channel. Off by default; identifiers can be pseudonymised with
  `identifier: hashed`.
- Failure logging through a PSR-3 logger on the `openid_connect` Monolog channel,
  at a level fixed per failure mode. `logging_options.logger` picks the logger
  service and defaults to the application logger.
- Mutation testing with [Infection](https://infection.github.io/)
  (`task test:mutation`), run in CI and reported to the Stryker dashboard
  (mutation score badge in README)

### Changed

- `OpenIdLoginAuthenticator::onAuthenticationFailure()` now chains the
  original exception via `previous` and includes its message, so logs and
  error reporters retain the cause (timeout vs. signature mismatch vs.
  wrong nonce). Symfony's security component still renders only the safe
  message key to the user.
- Strengthened tests guided by mutation testing; mutation score raised to
  100% with a CI threshold of 95 (`minCoveredMsi` in `infection.json5`)
- Test fixtures use RFC 2606 reserved domains (`provider.example.org`,
  `app.example.org`) instead of registrable domains
- CI: bumped `codecov/codecov-action` from `v5` to `v7` (restores Codecov's
  GPG signing key after the `codecovsecurity` account was removed, and moves
  the bundled `github-script` to Node 24) and set `fail_ci_if_error: false`
  so a Codecov outage no longer fails the build. No effect on the published
  package.
- `http_client_options.timeout` now defaults to `30` seconds when not set,
  so a slow or hung identity provider can no longer block worker processes
  indefinitely. Previously no timeout was applied and Guzzle's own default
  (`0` — wait forever) was used. Set `timeout: 0` to restore the old
  behaviour, or override per provider.

### Deprecated

- Not configuring `client_secret_expires_at` for a provider. An expired secret
  breaks every login and the bundle cannot warn about an expiry it does not know
  about. Will be required in 6.0.

### Fixed

- `CliLoginTokenAuthenticator` now chains the cause via `previous` in
  `onAuthenticationFailure()` and in the `CacheException`/`TokenNotFoundException`
  catch, instead of discarding it. Mirrors `OpenIdLoginAuthenticator`.

## [5.0.0] - 2026-06-02

### Changed (BREAKING)

- **Exception hierarchy reworked.** Every exception thrown from a public
  method now implements `OpenIdConnectBundleExceptionInterface` (which
  extends `\ItkDev\OpenIdConnect\Exception\OpenIdConnectExceptionInterface`
  from the upstream library). Concrete exceptions now extend the SPL type
  that best describes the failure category (`\RuntimeException`,
  `\InvalidArgumentException`); they no longer extend
  `ItkOpenIdConnectBundleException`. Consumers catching the abstract base
  must migrate to `OpenIdConnectBundleExceptionInterface` — the abstract
  class is kept for this release as a documented alias and is
  `@deprecated`, but `catch (ItkOpenIdConnectBundleException $e)` blocks
  will no longer match any concrete thrown by the bundle.
- Bumped `itk-dev/openid-connect` requirement to `^5.0` for the matching
  upstream contract.
- `OpenIdLoginAuthenticator::validateClaims` now catches on the marker
  interface (`OpenIdConnectExceptionInterface`) instead of the deprecated
  upstream abstract. The `$previous`-chain behaviour is preserved.
- `LoginController::login` catches on the marker interface before mapping
  to `ServiceUnavailableHttpException`. No consumer-visible behaviour
  change.

### Added

- `ItkDev\OpenIdConnectBundle\Exception\OpenIdConnectBundleExceptionInterface`
  marker for catching all bundle-thrown OIDC failures.
- Custom PHPStan rules (`ThrownExceptionImplementsBundleMarker`,
  `WrappedExceptionChainsPrevious`) that lock the exception contract on every
  CI run — thrown exceptions must implement the marker (with documented
  controller/authenticator carve-outs), and wraps inside a catch must chain
  the caught cause as `$previous`.
- `UPGRADE-5.0.md` migration guide for consumers.

### Changed

- Hardened static analysis. PHPStan now analyses `tests/` in addition to
  `src/`, runs the strict, deprecation, PHPUnit and Symfony rule packs, and
  requires a comment on every ignore (`reportIgnoresWithoutComments`). Pinned
  `phpstan/phpstan` to `^2.1.41`. No public-API or behavioural change.

### Deprecated

- `ItkDev\OpenIdConnectBundle\Exception\ItkOpenIdConnectBundleException`
  abstract class (catch `OpenIdConnectBundleExceptionInterface` instead).
  Will be removed in 6.0.

### Fixed

- Tests build real `Request` instances instead of stubbing `Request`, which
  fails under Symfony 8.1 (where `InputBag` is `final`) with recent PHPUnit.

## [4.2.0] - 2026-05-11

### Added

- Per-provider `cache_duration` option (seconds) forwarded to the
  underlying library; lets consumers tighten or extend the 24h default
  TTL for the cached OIDC discovery document and JWKS
- Per-provider `http_client_options` block (`timeout`, `proxy`, `verify`)
  forwarded to the underlying Guzzle HTTP client used by league/oauth2-client.
  Closes the long-standing inability to bound HTTP requests to the IdP.

### Fixed

- Preserve original cause via `$previous` in `CliLoginHelper` and
  `OpenIdLoginAuthenticator::validateClaims` exception wraps. Previously
  the message was copied but the chain to the originating PSR cache or
  upstream OIDC failure was lost, making logs harder to debug.

### Changed

- Mapped LoginController failures to 404 (unknown provider) or 503
  (upstream/cache) instead of a generic 500; cause chained via `previous`
- Expanded README note on Symfony native OIDC support (7.3 features,
  comparison table, link to upstream authorization-code-flow issue)
- Bumped actions/checkout from v5 to v6 in all GitHub workflows
- Renamed PHP_EXEC variable to PHP in Taskfile
- Added lint:composer task to Taskfile
- Improved test:coverage to enable XDEBUG_MODE and add text output
- Fixed test:matrix:reset to include CI profile when removing volumes
- Fixed test:run to remove stale composer.lock before updating dependencies

## [4.1.0] - 2026-03-20

### Added

- Added Symfony 8 support

### Changed

- Upgraded to PHPUnit 12
- Set phpstan to max level
- Set default for ADMIN_OIDC_ALLOW_HTTP to false in README to prevent unsafe settings in production

### Fixed

- Fixed type safety issues identified by phpstan max level
- Applied code style fixes
- Increased test coverage to 100%

## [4.0.1] - 2025-01-16

- Fix doctrine/orm require

## [4.0.0] - 2025-01-13

- Remove support for PHP 8.1 and 8.2 (BC)
- Remove support for Symfony versions lower than 6.4 (BC)
- Bump dependency requirements

## [3.1.0] - 2023-08-03

### Added

- Added support for `authorization code flow`.

### Removed

- Removed support for `openid connect implicit`.

## [3.0.3] - 2023-03-01

- Fixed return annotation.

## [3.0.2] - 2022-09-14

### Fixed

- State passed instead of nonce when validating id token

## [3.0.1] - 2022-09-13

### Fixed

- Auto wiring when `itkdev_openid_connect.user_provider` was configured

## [3.0.0] - 2022-09-13

### Added

- Support for multiple user providers
- Symfony 6.x support
- Rector tooling
- php-cs-fixer tooling

### Removed

- PHP 7.4 and 8.0 support
- phpcodesniffer

## [2.0.0] - 2021-12-08

### Added

- Migrated to Symfony's new (5.1+) security system

### Changed

- Require Symfony 5.4
- Moved `leeway` config to provider config
- ITK OpenID Connect: Upgraded from
  `itk-dev/openid-connect` 2.1.0 to 3.0.0

### Removed

- Remove support for PHP 7.3

## [1.1.0] - 2021-12-08

### Added

- Support for multiple open id connect configuration providers

## [1.0.1] - 2021-09-20

### Fixed

- Updated README
- Avoided duplicate cache configuration

## [1.0.0] - 2021-09-16

### Added

- README
- LICENSE
- OpenID Connect Bundle: Added bundle files, a login controller and an abstract authenticator.
- This CHANGELOG file to hopefully serve as an evolving example of a
  standardized open source project CHANGELOG.
- PHP-CS-Fixer
- Markdownlint
- Test Suite
- Psalm setup for static analysis
- Code formatting
- ITK OpenID Connect: Upgraded from
  `itk-dev/openid-connect` 1.0.0 to 2.1.0
- OpenId Connect Bundle: Added CLI login feature.

[unreleased]: https://github.com/itk-dev/openid-connect-bundle/compare/6.0.0...HEAD
[6.1.0]: https://github.com/itk-dev/openid-connect-bundle/compare/6.0.0...6.1.0
[6.0.0]: https://github.com/itk-dev/openid-connect-bundle/compare/5.1.1...6.0.0
[5.1.1]: https://github.com/itk-dev/openid-connect-bundle/compare/5.1.0...5.1.1
[5.1.0]: https://github.com/itk-dev/openid-connect-bundle/compare/5.0.0...5.1.0
[5.0.0]: https://github.com/itk-dev/openid-connect-bundle/compare/4.2.0...5.0.0
[4.2.0]: https://github.com/itk-dev/openid-connect-bundle/compare/4.1.0...4.2.0
[4.1.0]: https://github.com/itk-dev/openid-connect-bundle/compare/4.0.1...4.1.0
[4.0.1]: https://github.com/itk-dev/openid-connect-bundle/compare/4.0.0...4.0.1
[4.0.0]: https://github.com/itk-dev/openid-connect-bundle/compare/3.1.0...4.0.0
[3.1.0]: https://github.com/itk-dev/openid-connect-bundle/compare/3.0.3...3.1.0
[3.0.3]: https://github.com/itk-dev/openid-connect-bundle/compare/3.0.2...3.0.3
[3.0.2]: https://github.com/itk-dev/openid-connect-bundle/compare/3.0.1...3.0.2
[3.0.1]: https://github.com/itk-dev/openid-connect-bundle/compare/3.0.0...3.0.1
[3.0.0]: https://github.com/itk-dev/openid-connect-bundle/compare/2.0.0...3.0.0
[2.0.0]: https://github.com/itk-dev/openid-connect-bundle/compare/1.1.0...2.0.0
[1.1.0]: https://github.com/itk-dev/openid-connect-bundle/compare/1.0.1...1.1.0
[1.0.1]: https://github.com/itk-dev/openid-connect-bundle/compare/1.0.0...1.0.1
[1.0.0]: https://github.com/itk-dev/openid-connect-bundle/releases/tag/1.0.0

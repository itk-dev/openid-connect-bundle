# OpenId Connect Bundle

[![Github](https://img.shields.io/badge/source-itk--dev/openid--connect--bundle-blue?style=flat-square)](https://github.com/itk-dev/openid-connect-bundle)
[![Release](https://img.shields.io/packagist/v/itk-dev/openid-connect-bundle.svg?style=flat-square&label=release)](https://packagist.org/packages/itk-dev/openid-connect-bundle)
[![PHP Version](https://img.shields.io/packagist/php-v/itk-dev/openid-connect-bundle.svg?style=flat-square&colorB=%238892BF)](https://www.php.net/downloads)
[![Build Status](https://img.shields.io/github/actions/workflow/status/itk-dev/openid-connect-bundle/php.yaml?branch=develop&label=CI&logo=github&style=flat-square)](https://github.com/itk-dev/openid-connect-bundle/actions/workflows/php.yaml?query=branch%3Adevelop)
[![Codecov Code Coverage](https://img.shields.io/codecov/c/gh/itk-dev/openid-connect-bundle?label=codecov&logo=codecov&style=flat-square)](https://codecov.io/gh/itk-dev/openid-connect-bundle)
[![Mutation Score](https://img.shields.io/endpoint?style=flat-square&label=mutation%20score&url=https%3A%2F%2Fbadge-api.stryker-mutator.io%2Fgithub.com%2Fitk-dev%2Fopenid-connect-bundle%2Fdevelop)](https://dashboard.stryker-mutator.io/reports/github.com/itk-dev/openid-connect-bundle/develop)
[![Read License](https://img.shields.io/packagist/l/itk-dev/openid-connect-bundle.svg?style=flat-square&colorB=darkcyan)](https://github.com/itk-dev/openid-connect-bundle/blob/master/LICENSE.md)
[![Package downloads on Packagist](https://img.shields.io/packagist/dt/itk-dev/openid-connect-bundle.svg?style=flat-square&colorB=darkmagenta)](https://packagist.org/packages/itk-dev/openid-connect-bundle/stats)

Symfony bundle for authorization via OpenID Connect.

> [!NOTE]
>
> ## Symfony Native OIDC Support
>
> Status as of August 2026.
>
> Since this bundle was created Symfony has added [support for OpenID Connect](https://symfony.com/blog/new-in-symfony-6-3-openid-connect-token-handler)
> as documented in ["Using OpenID Connect (OIDC)"](https://symfony.com/doc/current/security/access_token.html#using-openid-connect-oidc).
>
> Symfony's native OIDC support has improved significantly in recent releases:
>
> * [OIDC discovery](https://github.com/symfony/symfony/pull/54932) was added in
>   Symfony 7.3 (May 2025), removing the need for manual keyset configuration.
>   Keys are fetched and cached automatically from the provider's
>   `.well-known/openid-configuration` endpoint.
> * [OAuth2 Token Introspection](https://symfony.com/blog/new-in-symfony-7-3-security-improvements)
>   (RFC 7662) support was added in Symfony 7.3, useful when access tokens are
>   opaque (not JWTs).
> * [JWE (encrypted token) support](https://github.com/symfony/symfony/pull/57721)
>   was added in Symfony 7.3 for OIDC token handlers.
>
> Everything released so far is designed for **stateless bearer token
> validation** (the `access_token` authenticator) only. It validates tokens that
> are already present on the request (e.g. in an `Authorization: Bearer` header),
> and does not implement the **authorization code flow** — the browser-based
> login where the application redirects to the IdP, handles the callback with an
> authorization code, exchanges it for tokens, and establishes a session. That
> gap is tracked in [symfony/symfony#50896](https://github.com/symfony/symfony/issues/50896).
>
> ### The authorization code flow is coming upstream
>
> A native `oidc_login` authenticator is being added in
> [symfony/symfony#64954](https://github.com/symfony/symfony/pull/64954),
> targeted at **Symfony 8.2 (November 2026)**. The pull request is in active
> review and is being reworked into a feature-complete implementation covering
> discovery, PKCE, configurable scopes and claims mapping, token-endpoint client
> authentication, and RP-initiated logout. One review question is still open —
> ID token signature verification, which this bundle's underlying library
> already does.
>
> ### What this bundle still provides
>
> | Feature                        | This bundle | Symfony native |
> |--------------------------------|:-----------:|:--------------:|
> | Authorization code flow        | ✅          | ⏳ ¹           |
> | Session-based browser login    | ✅          | ⏳ ¹           |
> | Multiple named OIDC providers  | ✅          | ❌ ²           |
> | CLI login tokens               | ✅          | ❌             |
> | Client secret expiry checks    | ✅          | ❌             |
> | OIDC discovery                 | ✅          | ✅             |
> | Bearer token validation (API)  | ❌          | ✅             |
> | OAuth2 token introspection     | ❌          | ✅             |
>
> ¹ In review for Symfony 8.2, see above.
>
> ² Symfony's `access_token` handler accepts multiple `issuers` for token
> validation, but this is not the same as this bundle's named provider model
> with distinct client credentials, redirect URIs, and selectable login routes
> per provider.
>
> ### What this means for the bundle
>
> Long term we expect Symfony core to replace most of this bundle. It is not
> there yet: multiple providers per firewall, CLI login and the client secret
> expiry checks have no upstream equivalent, and our applications track Symfony
> LTS releases.
>
> Until those gaps close the bundle remains fully supported. New features that
> upstream will provide are frozen; security and compatibility fixes continue. A
> deprecation will be announced here and in the [CHANGELOG](CHANGELOG.md) once a
> migration path exists — realistically no earlier than 2028.

Upgrading? See [UPGRADE-6.1.md](UPGRADE-6.1.md), and
[UPGRADE-6.0.md](UPGRADE-6.0.md) / [UPGRADE-5.0.md](UPGRADE-5.0.md) if you are coming
from an earlier major.

## Installation

To install run

```shell
composer require itk-dev/openid-connect-bundle
```

## Usage

Before being able to use the bundle, you must have your own User entity and
database setup.

Once you have this, you need to

* Configure variables for OpenId Connect
* Create an Authenticator class that extends the bundle authenticator,
  `OpenIdLoginAuthenticator`
* Configure `LoginTokenAuthenticator` in order to use CLI login.

### Variable configuration

In `/config/packages/` you need the following `itkdev_openid_connect.yaml` file
for configuring OpenId Connect variables

```yaml
itkdev_openid_connect:
  cache_options:
    cache_pool: 'cache.app' # Cache item pool for caching discovery document and CLI login tokens
  cli_login_options:
    route: '%env(string:OIDC_CLI_LOGIN_ROUTE)%' # Redirect route for CLI login
  user_provider: ~ #
  logging_options:
    # Optional: service id of the PSR-3 logger to receive failure logs.
    #           Defaults to the application logger. See "Logging" below.
    logger: 'monolog.logger.openid_connect'
  audit_options:
    # Optional: write an authentication audit trail. OFF by default because
    #           audit records identify people. See "Audit logging" below.
    enabled: false
  secret_expiry_options:
    # Optional: how many days ahead of expiry to start warning (default: 30).
    warning_days: 30
  openid_providers:
    # Define one or more providers
    # [providerKey]:
    #   options:
    #     metadata_url: …
    #     …
    admin:
      options:
        metadata_url: '%env(string:ADMIN_OIDC_METADATA_URL)%'
        client_id: '%env(string:ADMIN_OIDC_CLIENT_ID)%'
        client_secret: '%env(string:ADMIN_OIDC_CLIENT_SECRET)%'
        # Optional: date the client secret expires. Set it and the bundle warns
        #           before the secret expires; unset means the provider is not
        #           monitored and reports "unknown". Set it where the real secret
        #           lives. See "Client secret expiry" below.
        client_secret_expires_at: '%env(string:ADMIN_OIDC_CLIENT_SECRET_EXPIRES_AT)%'
        # Specify redirect URI
        redirect_uri: '%env(string:ADMIN_OIDC_REDIRECT_URI)%'
        # Optional: the path the callback arrives on, for a proxy that rewrites it
        #           without sending X-Forwarded-Prefix. Defaults to the path of
        #           redirect_uri, or of the generated redirect_route. See "Which
        #           requests count as a callback" below.
        callback_path: '/auth/callback'
        # Optional: Specify leeway (seconds) to account for clock skew between provider and hosting
        #           Defaults to 10
        leeway: '%env(int:ADMIN_OIDC_LEEWAY)%'
        # Optional: Cache duration (seconds) for the OIDC discovery document and JWKS
        #           Defaults to 86400 (24 hours)
        cache_duration: '%env(int:ADMIN_OIDC_CACHE_DURATION)%'
        # Optional: Allow (non-secure) http requests (used for mocking a IdP). NOT RECOMMENDED FOR PRODUCTION.
        #           Defaults to false
        allow_http: '%env(bool:ADMIN_OIDC_ALLOW_HTTP)%'
    user:
      options:
        metadata_url: '%env(string:USER_OIDC_METADATA_URL)%'
        client_id: '%env(string:USER_OIDC_CLIENT_ID)%'
        client_secret: '%env(string:USER_OIDC_CLIENT_SECRET)%'
        # As an alternative to using (a more or less) hardcoded redirect uri,
        # a Symfony route can be used as redirect URI
        redirect_route: 'default'
        # Define any params for the redirect_route
        # redirect_route_parameters: { type: user }
```

With the following `.env` environment variables

```text
###> itk-dev/openid-connect-bundle ###
# "admin" open id connect configuration variables (values provided by the OIDC IdP)
ADMIN_OIDC_METADATA_URL=ADMIN_APP_METADATA_URL
ADMIN_OIDC_CLIENT_ID=ADMIN_APP_CLIENT_ID
ADMIN_OIDC_CLIENT_SECRET=ADMIN_APP_CLIENT_SECRET
ADMIN_OIDC_CLIENT_SECRET_EXPIRES_AT=2027-01-31
ADMIN_OIDC_REDIRECT_URI=ADMIN_APP_REDIRECT_URI
ADMIN_OIDC_LEEWAY=30
ADMIN_OIDC_CACHE_DURATION=86400
ADMIN_OIDC_ALLOW_HTTP=false

# "user" open id connect configuration variables
USER_OIDC_METADATA_URL=USER_APP_METADATA_URL
USER_OIDC_CLIENT_ID=USER_APP_CLIENT_ID
USER_OIDC_CLIENT_SECRET=USER_APP_CLIENT_SECRET

# cli redirect url 
OIDC_CLI_LOGIN_ROUTE=OIDC_CLI_LOGIN_ROUTE
###< itk-dev/openid-connect-bundle ###
```

Set the actual values your `env.local` file to ensure they are not committed to Git.

#### Client secret expiry

An expired client secret breaks **every** login: the token exchange starts failing
with `invalid_client` and there is nothing in the flow that says why. The expiry
date is known when the secret is created, so telling the bundle about it turns an
outage into a calendar item.

```yaml
itkdev_openid_connect:
  secret_expiry_options:
    warning_days: 30 # default
  openid_providers:
    admin:
      options:
        client_secret_expires_at: '2027-01-31'
```

Any date `strtotime()` understands is accepted, and the value is normally supplied
from an environment variable as above. Date-only values are anchored to midnight
UTC so the day count does not drift with the time of day the check runs.

A value that cannot be parsed — a typo, or an environment variable that is set but
blank — reports the provider as `unknown` and logs an `error` saying it is not being
monitored. It is not a fatal error, because a mistyped date should not take an
application down; but it is not silent either, because the effect is that nothing is
watching that secret.

Each provider is then in one of four states:

| Status | Meaning |
| ------ | ------- |
| `unknown` | no date configured — nothing can be said |
| `ok` | more than `warning_days` remaining |
| `expiring_soon` | `warning_days` or fewer remaining |
| `expired` | the date has passed |

`unknown` is deliberately distinct from `ok`: an installation that has not set a
date is not fine, it is unmonitored.

What the bundle does with each state, when a login is attempted:

| Status | Behaviour |
| ------ | --------- |
| `expired` | a `critical` record; the login **still proceeds** |
| `expiring_soon` | a `warning` record; the login proceeds |
| `ok`, `unknown` | nothing logged |

**Nothing here blocks a login.** The status depends on a manually maintained date,
which can fall out of step with the secret it describes: rotate a secret without
updating `client_secret_expires_at` and the date reads `expired` while the secret
works perfectly. The date is therefore an indicator, not authority — the identity
provider is what decides whether a secret still works. These records exist so that
when it does stop working, the reason is already in the log.

For a genuinely expired secret that means the login still fails, at the callback,
with `invalid_client` — but the `critical` record here and the failure record from
the callback together name the cause without anyone having to reproduce it.

`client_secret_expires_at` is optional, and where you set it matters more than that
you set it. Put it with the real secret — the production secret store, or a `when@prod`
block. A date in a committed `.env` default is a date nobody maintains: it reports `ok`
while measuring nothing, which is worse than the `unknown` you get by leaving it out.

Quote it: YAML reads an unquoted `2027-01-31` as a number, and a value that is not a
string is rejected while the container compiles.

A provider still reaches `unknown` at runtime when the value resolves to something
unusable — an environment variable that is set but blank, or a date
`DateTimeImmutable` cannot parse — and that is reported at `error`, because an
unmonitored secret is no better than not having this feature.

##### Monitoring expiry

The records above only appear when somebody attempts a login, which is no help on a
quiet Sunday before a Monday-morning expiry. For scheduled monitoring, inject
`ClientSecretExpiryChecker` — it is a public service — and surface it through
whatever health endpoint the application already has:

```php
use ItkDev\OpenIdConnectBundle\Util\ClientSecretExpiry;
use ItkDev\OpenIdConnectBundle\Util\ClientSecretExpiryChecker;

// Shape will differ per application; this follows a tagged-service aggregator.
readonly class ClientSecretHealthCheck implements HealthCheckInterface
{
    public function __construct(private ClientSecretExpiryChecker $expiryChecker)
    {
    }

    public function getName(): string
    {
        return 'oidc_client_secret';
    }

    public function check(): HealthCheckResult
    {
        $statuses = $this->expiryChecker->getAllStatuses();

        $expired = array_filter($statuses, static fn (ClientSecretExpiry $e): bool => $e->isExpired());
        if ([] !== $expired) {
            return HealthCheckResult::degraded($this->getName(), sprintf(
                'Client secret expired for: %s',
                implode(', ', array_keys($expired)),
            ));
        }

        $expiring = array_filter($statuses, static fn (ClientSecretExpiry $e): bool => $e->isExpiringSoon());
        if ([] !== $expiring) {
            return HealthCheckResult::degraded($this->getName(), sprintf(
                'Client secret expires soon for: %s',
                implode(', ', array_keys($expiring)),
            ));
        }

        return HealthCheckResult::ok($this->getName());
    }
}
```

`getAllStatuses()` returns a `ClientSecretExpiry` per provider, keyed by provider
key, each with `isExpired()`, `isExpiringSoon()`, `status` and `toArray()`.

**The bundle ships no health endpoint of its own**, and that is deliberate:

* Monitoring should have one endpoint to poll. A second one, differently shaped and
  living under this bundle's route prefix, is the one that gets forgotten.
* The application owns how such an endpoint is authenticated. That reasoning can be
  subtle — an application whose user provider is database-backed may need to
  authenticate its health route at the edge rather than in Symfony, so the endpoint
  can still answer during a database outage. A route shipped by this bundle would
  sit outside that decision.
* The application owns what may be disclosed. Provider keys and expiry dates are
  information about a deployment, and whether they belong in a public readiness
  response or an authenticated detail response is not this bundle's call.

Exposing the data rather than a verdict also avoids a lossy mapping. The checker
distinguishes four states, and `unknown` — a provider with no date configured — is
not the same as healthy. Collapsing that into another library's pass/fail result
would throw the distinction away, whereas an application mapping it itself can
decide whether "nobody is tracking this secret" counts as degraded.

This composes with whatever health system is in use:

* a bespoke aggregator, as in the example above;
* `macpaw/symfony-health-check-bundle`, where checks implement its own
  `CheckInterface` and are listed by service id in configuration;
* `liip/monitor-bundle`, which auto-discovers any class implementing
  `Laminas\Diagnostics\Check\CheckInterface`.

If an adapter is ever shipped from here, that last one is the target: the
`laminas/laminas-diagnostics` contract depends on nothing but PHP, and its
`Success`/`Warning`/`Failure`/`Skip` results map onto this bundle's four states
almost exactly — including `Skip` for a provider with no date configured. It is not
shipped today because no consuming application uses it yet, and a dependency added
for hypothetical reach is a dependency to carry for nothing.

#### Logging

The bundle logs every login failure: an invalid state, an empty nonce, a failed
token exchange, an unknown or unreachable provider, and the CLI login token
paths. This is how a problem like an expired `client_secret` becomes visible —
the IdP's own message is logged, with the causing exception attached to the
record as `context['exception']`.

**The bundle decides how severe each failure is; your application decides which
levels it keeps.** Severity is not configurable, because it is a property of the
event rather than of a deployment:

| Event | Level |
| ----- | ----- |
| Token exchange or ID-token validation failed (an expired secret lands here) | `error` |
| Provider not configured, or the session lost its provider key | `error` |
| Identity provider unreachable, or the discovery cache failed | `error` |
| CLI login token could not be resolved, or resolved to a bad value | `error` |
| Invalid state, or a missing/empty nonce | `warning` |
| Unknown provider key requested | `warning` |
| No CLI login token provided | `warning` |

The `warning` events are routine and client-driven — a stale bookmark, a replayed
callback, a probe. The `error` events are the ones an operator needs to act on.

##### Default: nothing to configure

The bundle's services are tagged onto the `openid_connect` Monolog channel, so
records flow to whatever handlers your application already has. Your existing
`monolog` configuration therefore determines what is written, with no extra setup.

##### A dedicated channel with its own level

To give this bundle its own log file and threshold — for example to keep only
`error` and above — add a handler scoped to the channel, and exclude that channel
from your default handler so records are not written twice:

```yaml
monolog:
    handlers:
        # Everything except this bundle, at your usual level.
        main:
            type: stream
            path: '%kernel.logs_dir%/%kernel.environment%.log'
            level: debug
            channels: ['!openid_connect']

        # This bundle only, with its own threshold.
        openid_connect:
            type: stream
            path: '%kernel.logs_dir%/openid_connect.log'
            channels: ['openid_connect']
            level: error
```

The `level` key on the handler is what gives you "only errors and above". Raising
it filters out the `warning` events in the table above while keeping every
`error`.

##### Using a different logger service

`logging_options.logger` takes any PSR-3 service id. Note that it **replaces** the
channel logger rather than composing with it, so it is an escape hatch for sending
these records somewhere else entirely — not the way to filter them:

```yaml
itkdev_openid_connect:
  logging_options:
    logger: 'my_app.audit_logger'
```

##### Turning logging off

Point it at the `NullLogger` the bundle registers for the purpose:

```yaml
itkdev_openid_connect:
  logging_options:
    logger: 'itkdev_openid_connect.null_logger'
```

Your authenticator must be an autoconfigured service to receive a configured
logger, since it is applied through `registerForAutoconfiguration()`. That is the
default for services in `config/services.yaml`. With autoconfiguration disabled
the authenticator falls back to a `NullLogger` and logs nothing, while the rest of
the bundle keeps logging.

A configured logger also takes precedence over a `setLogger()` call on the
authenticator's own service definition. Disabling autoconfiguration is the way to
wire a logger yourself.

#### Audit logging

Separately from the failure logging above, the bundle can write an
**authentication audit trail**: who logged in, when, by which method, and which
attempts were refused. This answers a different question from the error log — "who
did what?" rather than "is something broken?" — which is why it is a separate
channel rather than another level.

> [!IMPORTANT]
> The audit trail records personal data (user identifiers, IP addresses). It is
> **off by default**, and enabling it makes retention, access control and the
> lawful basis for that processing your responsibility. Nothing is recorded, and
> no record is even assembled, while it is disabled.

```yaml
itkdev_openid_connect:
  audit_options:
    enabled: true
    # Optional: defaults to the application logger.
    logger: 'monolog.logger.openid_connect_audit'
    # Optional: 'raw' (default) or 'hashed'.
    identifier: raw
```

Records are written at `info` on the `openid_connect_audit` channel, with one
fixed context schema so the trail can be queried:

| Key | Meaning |
| --- | ------- |
| `event` | one of the event names below |
| `method` | `oidc` or `cli_token` — the coarse category to query on |
| `authenticator` | concrete authenticator class, `null` for CLI token issuance |
| `subject` | user identifier, or `null` where none is available |
| `provider` | OIDC provider key, `null` for CLI token logins |
| `firewall` | firewall that handled the login |
| `ip` | client IP |
| `outcome` | `success` or `failure` |
| `reason` | failure cause, `null` on success |

Events: `authentication.login_succeeded`, `authentication.login_failed`,
`authentication.cli_token_issued`, `authentication.cli_token_reissued`,
`authentication.cli_token_denied`.

Give it a handler that will not be filtered out by an operational threshold, and
retain it on whatever schedule your policy requires:

```yaml
monolog:
    handlers:
        openid_connect_audit:
            type: stream
            path: '%kernel.logs_dir%/openid_connect_audit.log'
            channels: ['openid_connect_audit']
            level: info
```

Only logins that went through **this bundle's** authenticators are recorded.
Symfony dispatches its login events for every authenticator in the application, so
if a project also offers password or API-token login, those events reach this
subscriber and are deliberately ignored: an OIDC bundle silently recording an
application's password logins would extend the personal-data processing past what
was opted into, and `provider` would be meaningless for them. Applications wanting
a complete authentication trail should subscribe to the same events themselves.

Both `method` and `authenticator` are recorded because they answer different
questions. `method` is stable and queryable; `authenticator` says which class
actually ran, which matters because consumers subclass `OpenIdLoginAuthenticator`
and an application may have several — one per provider, for instance.

Three details worth knowing:

* **Failed OIDC logins carry no `subject`.** This bundle raises its failures while
  building the passport, so at that point Symfony has no authenticated identity to
  report. The record still carries the provider, the IP and the reason.
* **CLI login tokens are never recorded.** The token is bearer-equivalent, so
  issuance is audited by subject only — the token and the login URL that embeds it
  stay out of the trail.

##### Pseudonymising identifiers

Setting `identifier: hashed` replaces the identifier with an HMAC-SHA256 keyed on
the application secret. It is stable, so records for the same person still
correlate, but it is not reversible from a list of known email addresses — which a
plain digest would be.

> [!NOTE]
> `identifier` cannot come from an environment variable. The key is chosen while the
> container compiles, so the mode has to be known then; an environment variable
> would leave it hashing with an empty key, which looks pseudonymised without being
> so. To vary it per environment, use Symfony's environment-specific configuration
> (`when@prod:`), which is resolved at compile time.

#### Configuring the HTTP client

Each provider accepts an optional `http_client_options` block that is forwarded
to the underlying Guzzle HTTP client used by `league/oauth2-client`. The bundle
applies a sensible default `timeout` of `30` seconds so a slow IdP cannot block
worker processes indefinitely (Guzzle's own default is `0`, i.e. wait forever).
Override it per provider, or set it to `0` to opt back into Guzzle's behaviour.

```yaml
itkdev_openid_connect:
  openid_providers:
    user:
      options:
        # ... existing keys ...
        # @see https://docs.guzzlephp.org/en/stable/request-options.html
        http_client_options:
          # Float describing the total timeout of the request in seconds. Defaults to 30; set to 0 to wait indefinitely.
          timeout: 5.0 
          # Pass a string to specify an HTTP proxy, or an array to specify different proxies for different protocols. (Default: none)
          proxy: "%env(string:HTTP_PROXY)%"
          # Describes the SSL certificate verification behavior of a request. (Default: true)
          verify: true 
```

The bundle accepts only `timeout`, `proxy`, and `verify` under
`http_client_options` — these are the keys `league/oauth2-client` forwards to
Guzzle (`verify` is consulted only when `proxy` is set). Any other key causes
an `InvalidConfigurationException` at container compile time.

> **Why Guzzle and not Symfony HttpClient?**
> `league/oauth2-client`, which the underlying `itk-dev/openid-connect`
> library extends, hard-types its HTTP client as `GuzzleHttp\ClientInterface`.
> Symfony HttpClient implements PSR-18 / HTTPlug, not Guzzle's interface, and
> no maintained adapter goes Symfony → Guzzle. Configure Guzzle via the
> options above; full transport replacement is not currently possible without
> a custom adapter we are not yet shipping.

In `/config/routes/` you need a similar `itkdev_openid_connect.yaml` file for
configuring the routing

```yaml
itkdev_openid_connect:
  resource: "@ItkDevOpenIdConnectBundle/src/Resources/config/routes.yaml"
  prefix: "/openidconnect" # Prefix for bundle routes
```

It is not necessary to add a prefix to the bundle routes, but in case you want
i.e. another `/login` route, it makes distinguishing between them easier.

When invoking the login controller action (route `itkdev_openid_connect_login`)
the key of a provider must be set in the `provider` parameter, e.g.

```twig
  <a href="{{ path('itkdev_openid_connect_login', {provider: 'user'}) }}">{{ 'Sign in'|trans }}</a>
```

```php
  $router->generate('itkdev_openid_connect_login', ['provider => 'user']);
```

Make sure to allow anonymous access to the login controller route, i.e.
something along the lines of

```yaml
# config/packages/security.yaml
security:
  # …
  access_control:
    # …
    - { path: ^/openidconnect/login(/.+)?$, role: IS_AUTHENTICATED_ANONYMOUSLY }
```

### CLI login

In order to use the CLI login feature the following environment variable must be
set in order for Symfony to be able to generate URLs in commands:

```shell
DEFAULT_URI=
```

See [Symfony documentation: Generating URLs in Commands](https://symfony.com/doc/current/routing.html#generating-urls-in-commands)
for more information.

You must also add the bundles `CliLoginTokenAuthenticator` to the `security.yaml`
file:

```yaml
security:
  firewalls:
    main:
      custom_authenticators:
        - ItkDev\OpenIdConnectBundle\Security\CliLoginTokenAuthenticator
```

Finally, configure the Symfony route to use for login links: `cli_login_options:
route`. If yoy have multiple firewalls that are active for different url patterns
you need to make sure you add `LoginTokenAuthenticator` to the firewall active
for the route specified here.

### Creating the Authenticator

The bundle can help you get the claims received from the authorizer – the only
functions that need to be implemented are `authenticate()`,
`onAuthenticationSuccess()` and `start()`.

```php
<?php

namespace App\Security;

use ItkDev\OpenIdConnect\Exception\ItkOpenIdConnectException;
use ItkDev\OpenIdConnectBundle\Security\OpenIdLoginAuthenticator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;

class SomeAuthenticator extends OpenIdLoginAuthenticator
{

    public function authenticate(Request $request): Passport
    {
        // Get the OIDC claims.
        try {
            $claims = $this->validateClaims($request);
            
            // Authentication success
            
            // TODO: Implement authenticate() method.
            
        } catch (ItkOpenIdConnectException $exception) {
            // Authentication failed. Chain the cause: the bundle reads it back in
            // onAuthenticationFailure() to decide what the user is shown.
            throw new CustomUserMessageAuthenticationException($exception->getMessage(), previous: $exception);
        }
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // Back to whatever the user was trying to reach, or your default.
        return $this->createTargetPathRedirect($request, $firewallName, '/');
    }

    public function start(Request $request, AuthenticationException $authException = null)
    {
        // TODO: Implement start() method.
    }
}
```

See below for [a full authenticator example](#example-authenticator-functions).

Make sure to add your authenticator to the `security.yaml` file - and if you
have more than one to add an entry point.

```yaml
security:
  firewalls:
    main:
        custom_authenticators:
          - App\Security\ExampleAuthenticator
          - ItkDev\OpenIdConnectBundle\Security\LoginTokenAuthenticator
        entry_point: App\Security\ExampleAuthenticator
```

With one authenticator per provider, override `getSupportedProviderKeys()` in each so
it only answers its own provider's callback:

```php
protected function getSupportedProviderKeys(): array
{
    return ['admin'];
}
```

Without the override every authenticator supports every callback path, Symfony asks
them in the order above, and the session's provider key decides which provider
validates the callback — which is how existing setups already work.

#### Which requests count as a callback

A request is treated as an OpenID Connect callback when it carries both `state` and
`code` **and** arrives on a provider's configured callback path — the path of
`redirect_uri`, of the generated `redirect_route`, or `callback_path` when set. Every
provider must declare one of the three.

`?state=…&code=…` on any other URL is ignored by the authenticator, and the firewall
handles the request as it would without them: an anonymous visitor is sent to your
entry point, a logged-in one gets the page.

The path is matched against `getBaseUrl()` plus `getPathInfo()`, so an application
deployed in a subdirectory, or behind a proxy that sends `X-Forwarded-Prefix` with
Symfony's [trusted proxies](https://symfony.com/doc/current/deployment/proxies.html)
configured, matches without further configuration: the prefix is part of the base URL
on the way in and part of `redirect_uri` on the way out.

Set `callback_path` when a proxy rewrites the path **without** announcing it — an
external `https://app.example.org/prefix/auth/callback` that arrives here as
`/auth/callback`. Nothing in the request says where the prefix went, so the path has to
be declared:

```yaml
callback_path: '/auth/callback'
```

Give it the path as this application receives it, including any base path of its own.

#### Returning to the originally requested page

`createTargetPathRedirect()` sends the user back to the page that triggered the login,
falling back to a URL of your choosing when there is nothing to go back to:

```php
public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
{
    return $this->createTargetPathRedirect($request, $firewallName, $this->router->generate('dashboard'));
}
```

Symfony saves the requested page when your entry point fires, so this works both for
applications that redirect straight to the identity provider and for those that show a
login screen with a provider link on it. The fallback covers a user who went to the
login link directly. The saved page is cleared on use, so a later visit to that link
does not replay it.

For a login link on a public page, where nothing was denied and so nothing was saved,
name the destination on the link itself:

```twig
<a href="{{ path('itkdev_openid_connect_login', {provider: 'admin', target_path: '/admin/reports'}) }}">Log in</a>
```

The value must be a path within the application: a single leading `/`, no backslash,
no `://`, no control characters. Anything else is dropped and logged at `warning`,
because it would otherwise turn the login route into an open redirect. When a page was
also denied, that page wins — it is what the user was actually stopped from reaching.

Only pages that exist and are access-controlled return this way, and that is by
design. Routing runs before security — `RouterListener` on `kernel.request` at
priority 32, the firewall at 8 — so a link to a URL with no route is a 404 before the
firewall is reached: no entry point fires, nothing is saved, and there is nothing to
come back to. A link to a page that exists but is public simply loads. Neither is
affected by the login flow.

#### Example authenticator functions

Here is an example using a `User` with a name and email property. First we
extract data from the claims, then check if this user already exists and finally
update/create it based on whether it existed or not.

```php
<?php

namespace App\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use ItkDev\OpenIdConnect\Exception\ItkOpenIdConnectException;
use ItkDev\OpenIdConnectBundle\Exception\InvalidProviderException;
use ItkDev\OpenIdConnectBundle\Security\OpenIdConfigurationProviderManager;
use ItkDev\OpenIdConnectBundle\Security\OpenIdLoginAuthenticator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

class AzureOIDCAuthenticator extends OpenIdLoginAuthenticator
{
    /**
     * AzureOIDCAuthenticator constructor
     *
     * @param EntityManagerInterface $entityManager
     * @param RequestStack $requestStack
     * @param UrlGeneratorInterface $router
     * @param OpenIdConfigurationProviderManager $providerManager
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly UrlGeneratorInterface $router,
        private readonly OpenIdConfigurationProviderManager $providerManager
    ) {
        parent::__construct($providerManager);
    }

    /** @inheritDoc */
    public function authenticate(Request $request): Passport
    {
        try {
            // Validate claims
            $claims = $this->validateClaims($request);

            // Extract properties from claims
            $name = $claims['name'];
            $email = $claims['upn'];

            // Check if user exists already - if not create a user
            $user = $this->entityManager->getRepository(User::class)
                ->findOneBy(['email'=> $email]);
            if (null === $user) {
                // Create the new user and persist it
                $user = new User();
                $this->entityManager->persist($user);
            }
            // Update/set user properties
            $user->setName($name);
            $user->setEmail($email);

            $this->entityManager->flush();

            return new SelfValidatingPassport(new UserBadge($user->getUserIdentifier()));
        } catch (ItkOpenIdConnectException|InvalidProviderException $exception) {
            throw new CustomUserMessageAuthenticationException($exception->getMessage(), previous: $exception);
        }
    }

    /** @inheritDoc */
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return $this->createTargetPathRedirect(
            $request,
            $firewallName,
            $this->router->generate('homepage_authenticated')
        );
    }

    /** @inheritDoc */
    public function start(Request $request, AuthenticationException $authException = null): Response
    {
        return new RedirectResponse($this->router->generate('itkdev_openid_connect_login', [
            'provider' => 'user',
        ]));
    }
}
```

### When the identity provider refuses

A provider that will not issue a code redirects back to the callback with an `error`
and no `code` — the user closed the consent screen, their session at the provider had
expired, a tenant policy said no. The bundle recognises that callback, spends the
one-time session values like any other, and throws `ProviderErrorException`.

It extends `AuthenticationFailedException`, so anything already catching the bundle's
login failure catches this too, and it implements Symfony's `HttpExceptionInterface`,
so the kernel answers a refusal with **403** rather than a 500 — 503 where the
provider reports its own trouble, 500 where the error says our request or
registration is wrong. Nothing is required of the application to get that.

The error code is an accessor, not something to search the message for:

```php
use ItkDev\OpenIdConnectBundle\Exception\ProviderErrorException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(KernelEvents::EXCEPTION, priority: 1)]
public function onLoginRefused(ExceptionEvent $event): void
{
    $exception = $event->getThrowable();

    if (!$exception instanceof ProviderErrorException) {
        return;
    }

    $template = ProviderErrorException::ACCESS_DENIED === $exception->getError()
        ? 'security/login_cancelled.html.twig'
        : 'security/login_failed.html.twig';

    $event->setResponse(new Response(
        $this->twig->render($template, ['error' => $exception->getError()]),
        $exception->getStatusCode(),
    ));
}
```

`error` and `error_description` reach you sanitized — control characters collapsed,
invalid UTF-8 dropped, capped at 200 characters — and neither is read at all until
the callback's state matches, so a forged callback cannot put text in your logs or on
your page. `getErrorDescription()` is whatever the provider sent, which may be
nothing; it is a diagnostic, not a message to show a user.

You can also pin the status and log level without writing a listener:

```yaml
framework:
  exceptions:
    ItkDev\OpenIdConnectBundle\Exception\ProviderErrorException:
      log_level: info
      status_code: 403
```

One thing is required of your authenticator: when `authenticate()` catches a bundle
exception and raises Symfony's, **chain the cause** — `previous: $exception`, as the
examples above do. The bundle reads it back to decide what the user is shown, and an
unchained failure arrives as a plain 500 with the reason only in the message.

If your application has its own listener that redirects 403 responses to a login
page, exclude `ProviderErrorException` from it. Otherwise a refusal is sent straight
back to the provider that refused it, which is the loop this handling exists to
prevent.

See [ADR 004](docs/adr/004-handle-provider-error-callbacks.md) for the reasoning.

## Sign in from command line

Rather than signing in via OpenId Connect, you can get a sign in url from the
command line by providing a username. Make sure to configure
`OIDC_CLI_REDIRECT_URL`. Run

```shell
bin/console itk-dev:openid-connect:login <username>
```

or

```shell
bin/console itk-dev:openid-connect:login --help
```

for details.

Be aware that a login token only can be used once before it is removed, and if
you used email as your user provider property the email goes into the `username`
argument.

## Development Setup

A `docker-compose.yml` file with a PHP 8.3+ image is included in this project.
A [Taskfile](https://taskfile.dev/) is used to run common development tasks.

To set up the project:

```shell
task setup
```

This starts the Docker containers and installs Composer dependencies.

### Running All CI Checks

To run all checks locally (coding standards, static analysis, tests):

```shell
task pr:actions
```

### Unit Testing

```shell
task test
```

### Test Matrix

Run the test suite across all supported PHP versions (8.3, 8.4, 8.5) with both
lowest and stable dependencies, mirroring the CI matrix:

```shell
task test:matrix
```

This runs PHPUnit with coverage for each combination and prints a summary of
pass/fail results.

### Mutation Testing

Line coverage shows which code the tests *execute*; mutation testing shows
which code they actually *verify*. [Infection](https://infection.github.io/)
applies small changes (mutants) to the source code — flipping a comparison,
removing a method call — and runs the test suite against each one. If the
tests still pass, the mutant "escaped": a potential bug the tests would not
catch.

```shell
task test:mutation
```

The minimum mutation score (`minCoveredMsi`) is defined in `infection.json5`
and enforced both locally and in CI — no command line flags needed. CI
annotates escaped mutants inline on pull requests, and results for `develop`
are published to the
[Stryker dashboard](https://dashboard.stryker-mutator.io/reports/github.com/itk-dev/openid-connect-bundle/develop),
which also feeds the mutation score badge above. Detailed reports are written
to `infection.log` and `infection.html` on each run.

### PHPStan Static Analysis

```shell
task analyze
```

### Coding Standards

Check all coding standards:

```shell
task lint
```

Fix PHP coding standards (php-cs-fixer):

```shell
task lint:php:fix
```

Fix Markdown files:

```shell
task lint:markdown:fix
```

Fix YAML files:

```shell
task lint:yaml:fix
```

### Available Tasks

Run `task --list` to see all available tasks.

## CI

GitHub Actions are used to run the test suite, mutation tests and code style
checks on all PRs.

## Versioning

We use [SemVer](http://semver.org/) for versioning. For the versions available,
see the [tags on this
repository](https://github.com/itk-dev/openid-connect/tags).

Upgrading across a major: [UPGRADE-6.0.md](UPGRADE-6.0.md),
[UPGRADE-5.0.md](UPGRADE-5.0.md). [CHANGELOG.md](CHANGELOG.md) has the rest.

## License

This project is licensed under the MIT License - see the
[LICENSE.md](LICENSE.md) file for details

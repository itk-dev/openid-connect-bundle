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
> However, Symfony's native OIDC support is designed for **stateless bearer
> token validation** (the `access_token` authenticator) only. It validates tokens
> that are already present on the request (e.g. in an `Authorization: Bearer`
> header).
>
> It does **not** implement the **authorization code flow** — the browser-based
> login where the application redirects to the IdP, handles the callback with an
> authorization code, exchanges it for tokens, and establishes a session. This
> is tracked upstream in [symfony/symfony#50896](https://github.com/symfony/symfony/issues/50896).
>
> This means the following features of this bundle have no native Symfony
> equivalent:
>
> | Feature                        | This bundle | Symfony native |
> |--------------------------------|:-----------:|:--------------:|
> | Authorization code flow        | ✅          | ❌             |
> | Session-based browser login    | ✅          | ❌             |
> | Multiple named OIDC providers  | ✅          | ❌ ¹           |
> | CLI login tokens               | ✅          | ❌             |
> | OIDC discovery                 | ✅          | ✅             |
> | Bearer token validation (API)  | ❌          | ✅             |
> | OAuth2 token introspection     | ❌          | ✅             |
>
> ¹ Symfony's `access_token` handler accepts multiple `issuers` for token
> validation, but this is not the same as this bundle's named provider model
> with distinct client credentials, redirect URIs, and selectable login routes
> per provider.
>
> If your application needs browser-based OIDC login, this bundle is still
> required.

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
        # Specify redirect URI
        redirect_uri: '%env(string:ADMIN_OIDC_REDIRECT_URI)%'
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
            // Authentication failed
            throw new CustomUserMessageAuthenticationException($exception->getMessage());
        }
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // TODO: Implement onAuthenticationSuccess() method.
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
        parent::__construct($providerManager, $requestStack);
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
            throw new CustomUserMessageAuthenticationException($exception->getMessage());
        }
    }

    /** @inheritDoc */
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return new RedirectResponse($this->router->generate('homepage_authenticated'));
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

## License

This project is licensed under the MIT License - see the
[LICENSE.md](LICENSE.md) file for details

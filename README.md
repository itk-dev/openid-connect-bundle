# OpenId Connect Bundle

[![Github](https://img.shields.io/badge/source-itk--dev/openid--connect--bundle-blue?style=flat-square)](https://github.com/itk-dev/openid-connect-bundle)
[![Release](https://img.shields.io/packagist/v/itk-dev/openid-connect-bundle.svg?style=flat-square&label=release)](https://packagist.org/packages/itk-dev/openid-connect-bundle)
[![PHP Version](https://img.shields.io/packagist/php-v/itk-dev/openid-connect-bundle.svg?style=flat-square&colorB=%238892BF)](https://www.php.net/downloads)
[![Build Status](https://img.shields.io/github/actions/workflow/status/itk-dev/openid-connect-bundle/pr.yaml?label=CI&logo=github&style=flat-square)](https://github.com/itk-dev/openid-connect-bundle/actions?query=workflow%3A%22Test+%26+Code+Style+Review%22)
[![Codecov Code Coverage](https://img.shields.io/codecov/c/gh/itk-dev/openid-connect-bundle?label=codecov&logo=codecov&style=flat-square)](https://codecov.io/gh/itk-dev/openid-connect-bundle)
[![Read License](https://img.shields.io/packagist/l/itk-dev/openid-connect-bundle.svg?style=flat-square&colorB=darkcyan)](https://github.com/itk-dev/openid-connect-bundle/blob/master/LICENSE.md)
[![Package downloads on Packagist](https://img.shields.io/packagist/dt/itk-dev/openid-connect-bundle.svg?style=flat-square&colorB=darkmagenta)](https://packagist.org/packages/itk-dev/openid-connect-bundle/stats)

Symfony bundle for authorization via OpenID Connect.

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
        # Optional: Allow http requests (used for mocking a IdP)
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
ADMIN_OIDC_ALLOW_HTTP=true

# "user" open id connect configuration variables
USER_OIDC_METADATA_URL=USER_APP_METADATA_URL
USER_OIDC_CLIENT_ID=USER_APP_CLIENT_ID
USER_OIDC_CLIENT_SECRET=USER_APP_CLIENT_SECRET

# cli redirect url 
OIDC_CLI_LOGIN_ROUTE=OIDC_CLI_LOGIN_ROUTE
###< itk-dev/openid-connect-bundle ###
```

Set the actual values your `env.local` file to ensure they are not committed to Git.

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

A [`docker-compose.yml`](docker-compose.yml) file with a PHP 8.1 image is
included in this project. To install the dependencies you can run

```shell
docker compose up -d
docker compose exec phpfpm composer install
```

### Unit Testing

A PhpUnit setup is included in this library. To run the unit tests:

```shell
docker compose exec phpfpm composer install
docker compose exec phpfpm ./vendor/bin/phpunit
```

### Psalm static analysis

We’re using [Psalm](https://psalm.dev/) for static analysis. To run psalm do

```shell
docker compose exec phpfpm composer install
docker compose exec phpfpm ./vendor/bin/psalm
```

### Check Coding Standard

The following command let you test that the code follows the coding standard for
the project.

* PHP files (PHP-CS-Fixer)

    ```shell
    docker compose exec phpfpm composer coding-standards-check
    ```

* Markdown files (markdownlint standard rules)

    ```shell
    docker run --rm -v "$PWD":/usr/src/app -w /usr/src/app node:18 yarn install
    docker run --rm -v "$PWD":/usr/src/app -w /usr/src/app node:18 yarn coding-standards-check
    ```

### Apply Coding Standards

To attempt to automatically fix coding style

* PHP files (PHP-CS-Fixer)

    ```sh
    docker compose exec phpfpm composer coding-standards-apply
    ```

* Markdown files (markdownlint standard rules)

    ```shell
    docker run --rm -v "$PWD":/usr/src/app -w /usr/src/app node:18 yarn install
    docker run --rm -v "$PWD":/usr/src/app -w /usr/src/app node:18 yarn coding-standards-apply
    ```

## CI

GitHub Actions are used to run the test suite and code style checks on all PRs.

If you wish to test against the jobs locally you can install
[act](https://github.com/nektos/act). Then do:

```shell
act -P ubuntu-latest=shivammathur/node:latest pull_request
```

## Versioning

We use [SemVer](http://semver.org/) for versioning. For the versions available,
see the [tags on this
repository](https://github.com/itk-dev/openid-connect/tags).

## License

This project is licensed under the MIT License - see the
[LICENSE.md](LICENSE.md) file for details

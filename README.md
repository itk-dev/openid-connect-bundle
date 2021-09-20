# OpenId Connect Bundle

Symfony bundle for authorization via OpenID Connect.

## Installation

To install run

```shell
composer require itk-dev/openid-connect-bundle
```

## Usage

Before being able to use the bundle,
you must have your own User entity and database setup.

Once you have this, you need to

* Configure variables for OpenId Connect
* Create an Authenticator class that extends
the bundle authenticator, `OpenIdLoginAuthenticator`
* Configure `LoginTokenAuthenticator` in order to use CLI login.

### Variable configuration

In `/config/packages/` you need the following `itkdev_openid_connect.yaml`
file for configuring OpenId Connect variables

```yaml
itkdev_openid_connect:
  cache_options: 
    cache_pool: 'cache.app' # Cache item pool for caching discovery document and CLI login tokens
  openid_provider_options:
    configuration_url: '%env(CONFIGURATION_URL)%' # url to OpenId Discovery document
    client_id: '%env(CLIENT_ID)%' # Client id assigned by authorizer
    client_secret: '%env(CLIENT_SECRET)%' # Client password assigned by authorizer
    callback_uri: '%env(CALLBACK_URI)%' # Callback URI registered at identity provider
  cli_login_options:
    cli_redirect: '%env(CLI_REDIRECT)%' # Redirect route for CLI login
```

With the following `.env` environment variables

```text
###> itk-dev/openid-connect-bundle ###
CONFIGURATION_URL=APP_CONFIGURATION_URL
CLIENT_ID=APP_CLIENT_ID
CLIENT_SECRET=APP_CLIENT_SECRET
CALLBACK_URI=APP_CALLBACK_URI
CLI_REDIRECT=APP_CLI_REDIRECT_URI
###< itk-dev/openid-connect-bundle ###
```

In `/config/routes/` you need a similar
`itkdev_openid_connect.yaml` file for configuring the routing

```yaml
itkdev_openid_connect:
  resource: "@ItkDevOpenIdConnectBundle/src/Resources/config/routes.yaml"
  prefix: "/openidconnect" # Prefix for bundle routes  
```

It is not necessary to add a prefix to the bundle routes,
but in case you want i.e. another `/login` route,
it makes distinguishing between them easier.

### CLI login

In order to use the CLI login feature the following
environment variable must be set:

```shell
DEFAULT_URI=
```

See [Symfony documentation](https://symfony.com/doc/current/routing.html#generating-urls-in-commands)
for more information.

You must also add the Bundle `LoginTokenAuthenticator`
to the `security.yaml` file:

```yaml
security:
  firewalls:
    main:
      guard:
        authenticators:
          - ItkDev\OpenIdConnectBundle\Security\LoginTokenAuthenticator
```

### Creating the Authenticator

The bundle handles the extraction of credentials received from the authorizer -
therefore the only functions that needs to be implemented are `getUser()`,
`onAuthenticationSuccess()` and `start()`.

```php
<?php

namespace App\Security;

use ItkDev\OpenIdConnectBundle\Security\OpenIdLoginAuthenticator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserProviderInterface;

class SomeAuthenticator extends OpenIdLoginAuthenticator
{

    public function getUser($credentials, UserProviderInterface $userProvider)
    {
        // TODO: Implement getUser() method.
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $providerKey)
    {
        // TODO: Implement onAuthenticationSuccess() method.
    }

    public function start(Request $request, AuthenticationException $authException = null)
    {
        // TODO: Implement start() method.
    }
}
```

Make sure to add your authenticator to the `security.yaml` file -
and if you have more than one to add an entry point.

```yaml
security:
  firewalls:
    main:
      guard:
        authenticators:
          - App\Security\ExampleAuthenticator
          - ItkDev\OpenIdConnectBundle\Security\LoginTokenAuthenticator
        entry_point: App\Security\ExampleAuthenticator
```

#### Example authenticator functions

Here is an example using a `User` with a name and email property.
First we extract data from the credentials,
then check if this user already exists and
finally update/create it based on whether it existed or not.

```php
<?php

namespace App\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use ItkDev\OpenIdConnect\Security\OpenIdConfigurationProvider;
use ItkDev\OpenIdConnectBundle\Security\OpenIdLoginAuthenticator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserProviderInterface;

class ExampleAuthenticator extends OpenIdLoginAuthenticator
{
    /**
     * @var UrlGeneratorInterface
     */
    private $router;
    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager, int $leeway, SessionInterface $session, UrlGeneratorInterface $router, OpenIdConfigurationProvider $provider)
    {
        $this->router = $router;
        $this->entityManager = $entityManager;
        parent::__construct($provider, $session, $leeway);
    }

    public function getUser($credentials, UserProviderInterface $userProvider)
    {
        // Extract properties from credentials
        $name = $credentials['name'];
        $email = $credentials['upn'];

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

        return $user;
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $providerKey)
    {
        return new RedirectResponse($this->router->generate('homepage_authenticated'));
    }

    public function start(Request $request, AuthenticationException $authException = null)
    {
        return new RedirectResponse($this->router->generate('itkdev_openid_connect_login'));
    }
}
```

For this example we have bound `$leeway` via `.env`
and `services.yaml`:

```text
###> itk-dev/openid-connect-bundle ###
LEEWAY=10
###< itk-dev/openid-connect-bundle ###
```

```yaml
services:
    _defaults:
        bind:
            $leeway: '%env(LEEWAY)%'
```

## Sign in from command line

Rather than signing in via OpenId Connect, you can get
a sign in url from the command line by providing a username.
Make sure to configure `DEFAULT_URI`. Run

```shell
bin/console itk-dev:openid-connect:login <username>
```

Or

```shell
bin/console itk-dev:openid-connect:login --help
```

for details.

Be aware that a login token only can be used once
before it is removed, and if you used email as your user provider property
the email goes into the `username` argument.

## Changes for Symfony 6.0

In Symfony 6.0 a new security system is
[introduced](https://symfony.com/doc/current/security/experimental_authenticators.html).
This system is said to be almost fully backwards compatible, but changes may be needed.
If so, a new version of this bundle might be necessary.

## Development Setup

A `docker-compose.yml` file with a PHP 7.4 image is included in this project.
To install the dependencies you can run

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

Where using [Psalm](https://psalm.dev/) for static analysis. To run
psalm do

```shell
docker compose exec phpfpm composer install
docker compose exec phpfpm ./vendor/bin/psalm
```

### Check Coding Standard

The following command let you test that the code follows
the coding standard for the project.

* PHP files (PHP-CS-Fixer)

    ```shell
    docker compose exec phpfpm composer check-coding-standards
    ```

* Markdown files (markdownlint standard rules)

    ```shell
    docker run -v ${PWD}:/app itkdev/yarn:latest install
    docker run -v ${PWD}:/app itkdev/yarn:latest coding-standards-check
    ```

### Apply Coding Standards

To attempt to automatically fix coding style

* PHP files (PHP-CS-Fixer)

    ```sh
    docker compose exec phpfpm composer apply-coding-standards
    ```

* Markdown files (markdownlint standard rules)

    ```shell
    docker run -v ${PWD}:/app itkdev/yarn:latest install
    docker run -v ${PWD}:/app itkdev/yarn:latest coding-standards-apply
    ```

## CI

Github Actions are used to run the test suite and code style checks on all PR's.

If you wish to test against the jobs locally you can install [act](https://github.com/nektos/act).
Then do:

```shell
act -P ubuntu-latest=shivammathur/node:latest pull_request
```

## Versioning

We use [SemVer](http://semver.org/) for versioning.
For the versions available, see the
[tags on this repository](https://github.com/itk-dev/openid-connect/tags).

## License

This project is licensed under the MIT License - see the
[LICENSE.md](LICENSE.md) file for details

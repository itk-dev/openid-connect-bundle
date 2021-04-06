# OpenId Connect Bundle

Symfony bundle for authorization via OpenID Connect.

## Installation

To install run

```shell
composer require itk-dev/openid-connect-bundle
```

If you wish to run the coding standard tests for Markdown files

```sh
yarn install
```

## Usage

Before being able to use the bundle,
you must have your own User entity and a database setup.

Once you have this, you need to configure bundle variables,
create an authenticator class that extends `OpenIdLoginAuthenticator`
and one that extends `LoginTokenAuthenticator`.

### Variable configuration

In `/config/packages/` you need the following `itk_dev_openid_connect.yaml`
file for configuring table name and OpenId-Connect variables:

```yaml
itk_dev_openid_connect:
  cli_login_table: 'table_name' # Name for table containing login tokens
  open_id_provider_options:
    configuration_url: 'https://.../openid-configuration..' # url to OpenId Discovery document
    client_id: 'client_id' # Client id assigned by authorizer
    client_secret: 'client_secret' # Client password assigned by authorizer
    cache_path: '' # Path for caching discovery document
    callback_uri: 'absolute_uri_here' # Callback URI registered at identity provider
```

After choosing a fitting table name e.g. `itk_dev_login_table`,
it is important that you add this to your doctrine
[schema filter](https://symfony.com/doc/current/bundles/DoctrineMigrationsBundle/index.html#manual-tables),
in `/config/packages/doctrine.yaml`:

```yaml
doctrine:
    dbal:
        schema_filter: ~^(?!itk_dev_)~
```

If this is not done, doctrine will remove this table when migrating.

In `/config/routes/` you need a similar
`itk_dev_openid_connect.yaml` file for configuring the routing

```yaml
itk_dev_openid_connect:
  resource: "@ItkDevOpenIdConnectBundle/config/routes.yaml"
  prefix: "/openidconnect" # Prefix for bundle routes  
```

It is not necessary to add a prefix to the bundle routes,
but in case you want e.g. another `/login` route,
it makes distinguishing between them easier. To begin
the authorization flow you will need to redirect to
`/openidconnect/login`, if you used the `/openidconnect` prefix.

### Creating the authenticators

#### The OpenIdLoginAuthenticator

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

class SomeOpenIdAuthenticator extends OpenIdLoginAuthenticator
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

Similarly, you need a `SomeLoginTokenAuthenticator` that extends
`LoginTokenAuthenticator`. This however only requires implementation
of `onAuthenticationSuccess()` and `start()`.

#### Configuration of `security.yaml`

Make sure to add your authenticators to the `security.yaml` file
and also add a [entry point](https://symfony.com/doc/current/security/multiple_guard_authenticators.html).
Furthermore, you need to configure the user provider for the authenticators:

```yaml
security:
  providers:
    app_user_provider:
      entity:
        class: App\Entity\User
        property: 'email'
  firewalls:
    main:
      provider: app_user_provider
      guard:
        authenticators:
          - App\Security\SomeOpenIdAuthenticator
          - App\Security\SomeLoginTokenAuthenticator
        entry_point: App\Security\SomeOpenIdAuthenticator
```

The property is set to email as this is unique in most cases,
but this is not required. Just be aware that whatever you configure
it to is the argument to be provided during CLI login,
and that the following example uses the above email configuration.

#### Example authenticator functions

Here is an example using a `User` with a name and email property.
First we extract data from the credentials,
then check if this user already exists via user provider and
finally update/create it based on whether it existed or not.

```php
<?php

namespace App\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use ItkDev\OpenIdConnectBundle\Security\OpenIdLoginAuthenticator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\UsernameNotFoundException;
use Symfony\Component\Security\Core\User\UserProviderInterface;

class TestAuthenticator extends OpenIdLoginAuthenticator
{
    /**
     * @var UrlGeneratorInterface
     */
    private $router;
    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager, SessionInterface $session, UrlGeneratorInterface $router)
    {
        $this->router = $router;
        $this->entityManager = $entityManager;
        parent::__construct($session);
    }

    public function getUser($credentials, UserProviderInterface $userProvider)
    {
        $name = $credentials['name'];
        $email = $credentials['upn'];

        //Check if user exists already - if not create a user
        try {
            $user = $userProvider->loadUserByUsername($email);
        } catch (UsernameNotFoundException $e){
            $user = new User();
        }
        
        // Update/set names here
        $user->setName($name);
        $user->setEmail($email);

        // persist and flush user to database
        // If no change persist will recognize this
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $providerKey)
    {
        return new RedirectResponse($this->router->generate('homepage_authenticated'));
    }

    public function start(Request $request, AuthenticationException $authException = null)
    {
        return new RedirectResponse($this->router->generate('itk_dev_openid_connect_login'));
    }
}
```

### CLI login

In order to use the CLI login feature the following
environment variable must be set:

```shell
DEFAULT_URI=
```

See [Symfony documentation](https://symfony.com/doc/current/routing.html#generating-urls-in-commands)
for more information.

## Sign in from command line

Rather than signing in via OpenId Connect, you can get
a sign in url from the command line by providing a user email. Run

```shell
bin/console itk-dev:openid-connect:login <email>
```

Or simply run

```shell
bin/console itk-dev:openid-connect:login --help
```

for details. Be aware that a login token only can be used once
before it is removed.

## Changes for Symfony 6.0

In Symfony 6.0 a new security system is
[introduced](https://symfony.com/doc/current/security/experimental_authenticators.html).
This system is said to be almost fully backwards compatible, but changes may be needed.
If so, a new version of this bundle might be necessary.

## Coding standard tests

The following command let you test that the code follows
the coding standard we decided to adhere to in this project.

* PHP files (PHP-CS-Fixer)

    ```sh
    ./vendor/bin/php-cs-fixer fix src --dry-run
    ```

* Markdown files (markdownlint standard rules)

    ```sh
    yarn coding-standards-check
    ```

## Versioning

We use [SemVer](http://semver.org/) for versioning.
For the versions available, see the
[tags on this repository](https://github.com/itk-dev/openid-connect-bundle/tags).

## License

This project is licensed under the MIT License - see the
[LICENSE.md](LICENSE.md) file for details.

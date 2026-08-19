# Upgrading from 5.x to 6.0

A failed OpenID Connect callback now throws
`\ItkDev\OpenIdConnectBundle\Exception\AuthenticationFailedException` instead of
Symfony's `AuthenticationException`.

Before 6.0 the security component caught that exception and redirected to the
identity provider again, so a permanent failure such as an expired client secret
looped forever with no error page. The new exception escapes the firewall, so your
application renders it — a 500 by default.

See [ADR 002](docs/adr/002-fail-closed-on-authentication-failure.md).

## Migrate catch blocks

```diff
- } catch (\Symfony\Component\Security\Core\Exception\AuthenticationException $e) {
+ } catch (\ItkDev\OpenIdConnectBundle\Exception\OpenIdConnectBundleExceptionInterface $e) {
```

If you catch nothing today, no code change is needed. Check that a failed login
renders an acceptable error and that your error reporting picks it up.

## Rendering something friendlier than a 500

Listen for the exception. Do not answer with a redirect to the login route — that
reintroduces the loop.

```php
#[AsEventListener]
final class LoginFailureListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        if ($event->getThrowable() instanceof AuthenticationFailedException) {
            $event->setResponse(new Response($this->twig->render('login_failed.html.twig'), 503));
        }
    }
}
```

## CLI login is unchanged

`CliLoginTokenAuthenticator` still throws `AuthenticationException`, so a consumed
or invalid login token still sends the user to your login page. That path has no
entry point of its own and cannot loop.

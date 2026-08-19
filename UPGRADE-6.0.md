# Upgrading from 5.x to 6.0

6.0 changes what happens when an OpenID Connect callback cannot be validated: the
bundle now fails closed instead of sending the user back to the identity provider.

Before 6.0 a failed callback threw an `AuthenticationException`, which Symfony's
security component catches and answers by calling the firewall's entry point —
another redirect to the identity provider. When the failure was permanent, such as
an expired client secret, that produced an unbreakable redirect loop with no error
page and nothing in the logs. This is what took `sites.itkdev.dk` down on
2026-08-12.

See the architecture decision in
[docs/adr/002-fail-closed-on-authentication-failure.md](docs/adr/002-fail-closed-on-authentication-failure.md).

## A failed login is now an error, not a redirect

`OpenIdLoginAuthenticator::onAuthenticationFailure()` throws
`\ItkDev\OpenIdConnectBundle\Exception\AuthenticationFailedException`, which is
**not** a `Symfony\Component\Security\Core\Exception\AuthenticationException`. The
firewall therefore does not catch it: it reaches `HttpKernel`, and your application
renders it like any other unhandled exception — a 500 by default.

**What you need to do depends on what you have today.**

If you catch `AuthenticationException` anywhere around the login callback, that
catch no longer matches:

```diff
- } catch (\Symfony\Component\Security\Core\Exception\AuthenticationException $e) {
+ } catch (\ItkDev\OpenIdConnectBundle\Exception\OpenIdConnectBundleExceptionInterface $e) {
```

Catching the bundle marker is the recommended form — per
[ADR 001](docs/adr/001-marker-interface-exception-hierarchy.md) it covers every
failure this bundle and the upstream library raise. `AuthenticationFailedException`
extends `\RuntimeException`, so a `catch (\RuntimeException $e)` also matches.

If you catch nothing, you need no code change. Verify that a failed login renders
an acceptable error page and that your error reporting picks it up.

## Rendering something friendlier than a 500

The bundle ships no templates and takes no view on your layout, so it does not
render an error page for you. To show one, listen for the exception:

```php
use ItkDev\OpenIdConnectBundle\Exception\AuthenticationFailedException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

#[AsEventListener]
final class LoginFailureListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        if (!$event->getThrowable() instanceof AuthenticationFailedException) {
            return;
        }

        // The cause is chained as $previous, and has already been logged by the
        // bundle on the `openid_connect` channel.
        $event->setResponse(new Response($this->twig->render('login_failed.html.twig'), 503));
    }
}
```

Do not answer such a listener with a redirect back to the login route. That
reintroduces the loop this release exists to remove.

## The CLI login authenticator is unchanged

`CliLoginTokenAuthenticator` still throws `AuthenticationException`, so a
consumed or invalid login token still sends the user to your normal login page.
That path cannot loop — it has no entry point of its own, and the redirect carries
no `loginToken` — and for a single-use token that has already been used, arriving
at the login page is friendlier than an error. If you have a catch around the CLI
login flow specifically, it keeps working.

<?php

namespace ItkDev\OpenIdConnectBundle\Tests\Security;

use ItkDev\OpenIdConnectBundle\EventSubscriber\AuthenticationAuditSubscriber;
use ItkDev\OpenIdConnectBundle\Exception\AuthenticationFailedException;
use ItkDev\OpenIdConnectBundle\Tests\ItkDevOpenIdConnectBundleTestingKernel;
use ItkDev\OpenIdConnectBundle\Tests\RestoresExceptionHandlers;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\PreAuthenticatedToken;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * The loop, reproduced through a real firewall.
 *
 * Asserting the thrown type in a unit test is necessary but not sufficient. The
 * security `ExceptionListener` walks the whole `$previous` chain, so an exception
 * of the right type carrying an `AuthenticationException` as its cause is still
 * converted back into an entry-point redirect. Only a booted firewall shows that,
 * which is why this dispatches a request rather than calling the authenticator.
 */
class FailedCallbackDoesNotLoopTest extends TestCase
{
    use RestoresExceptionHandlers;

    private ItkDevOpenIdConnectBundleTestingKernel $kernel;

    protected function setUp(): void
    {
        $this->captureExceptionHandler();
        $this->kernel = new ItkDevOpenIdConnectBundleTestingKernel([
            __DIR__.'/../config/framework.yml',
            __DIR__.'/../config/framework_routing.yml',
            __DIR__.'/../config/security_consumer.yml',
            __DIR__.'/../config/itkdev_openid_connect.yml',
        ]);
        $this->kernel->boot();
    }

    protected function tearDown(): void
    {
        $this->restoreExceptionHandlers();
    }

    /**
     * A callback whose state does not match the session: the shape of every
     * failure the outage produced, an expired client secret included.
     */
    private function failingCallback(): Request
    {
        $request = Request::create('/callback_uri?state=does-not-match&code=some-code');
        $session = new Session(new MockArraySessionStorage());
        $session->set('oauth2provider', 'test_provider_1');
        $session->set('oauth2state', 'the-real-state');
        $session->set('oauth2nonce', 'the-real-nonce');
        $request->setSession($session);

        return $request;
    }

    /**
     * Guards against the whole test passing vacuously. An unroutable path or a
     * firewall that does not match gives a 500 with no redirect too, and every
     * assertion below would then hold for the wrong reason. `validateClaims()`
     * publishes the provider it resolved, so the attribute is proof it ran.
     */
    private function assertTheAuthenticatorRejectedTheCallback(Request $request): void
    {
        $this->assertSame(
            'test_provider_1',
            $request->attributes->get(AuthenticationAuditSubscriber::PROVIDER_ATTRIBUTE),
            'The request never reached validateClaims(), so this test proves nothing.'
        );
    }

    public function testAFailedCallbackIsNotAnsweredWithARedirect(): void
    {
        $request = $this->failingCallback();
        $response = $this->kernel->handle($request, catch: true);

        $this->assertTheAuthenticatorRejectedTheCallback($request);
        $this->assertNull(
            $response->headers->get('Location'),
            'A failed callback was answered with a redirect: the firewall re-entered its entry point and the loop is back.'
        );
        $this->assertSame(
            Response::HTTP_INTERNAL_SERVER_ERROR,
            $response->getStatusCode(),
            'The failure should surface as an error the application renders.'
        );
    }

    public function testTheExceptionAndItsWholeCauseChainStayOutsideTheSecurityHierarchy(): void
    {
        $request = $this->failingCallback();

        try {
            $this->kernel->handle($request, catch: false);
            $this->fail('A failed callback should not be handled silently.');
        } catch (AuthenticationFailedException $exception) {
            // Catching the concrete type narrows it statically, which the unit test
            // in OpenIdLoginAuthenticatorTest deliberately avoids. That guard belongs
            // there and this test does not repeat it: what is under test here is the
            // chain, and catching the type is how we get hold of it. Do not "align"
            // the two tests by moving the narrowing into that one.
            $this->assertTheAuthenticatorRejectedTheCallback($request);

            for ($cause = $exception; null !== $cause; $cause = $cause->getPrevious()) {
                $this->assertNotInstanceOf(
                    AuthenticationException::class,
                    $cause,
                    'An AuthenticationException in the chain is enough for the ExceptionListener to redirect: it walks $previous.'
                );
            }

            $this->assertStringContainsString('Invalid state', $exception->getMessage(), 'The cause is still reported');
        }
    }

    /**
     * The observable fix for issue #63.
     *
     * `state` and `code` on a path that is not a callback used to enter the flow and,
     * since the bundle fails closed, surface as a 500 that any unauthenticated caller
     * could raise on any URL. It is the firewall's business again: an anonymous
     * request is sent to the entry point, exactly as it would be without the query
     * string.
     */
    public function testAStrayCallbackIsLeftToTheFirewall(): void
    {
        $request = Request::create('/protected?state=forged&code=forged');
        $request->setSession(new Session(new MockArraySessionStorage()));

        $response = $this->kernel->handle($request, catch: true);

        $this->assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        $this->assertSame(ConsumerAuthenticator::LOGIN_PATH, $response->headers->get('Location'));
        $this->assertNull(
            $request->attributes->get(AuthenticationAuditSubscriber::PROVIDER_ATTRIBUTE),
            'validateClaims() ran, so the authenticator accepted a callback on a path that is not one'
        );
    }

    /**
     * Symfony's half of "return to the page you asked for": the entry point fires and
     * the target path is saved. Pinned here so a framework upgrade cannot quietly
     * drop it and leave createTargetPathRedirect() with nothing to read.
     */
    public function testTheEntryPointSavesTheRequestedPage(): void
    {
        $request = Request::create('/protected');
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);

        $response = $this->kernel->handle($request, catch: true);

        $this->assertSame(ConsumerAuthenticator::LOGIN_PATH, $response->headers->get('Location'));
        $this->assertSame('http://localhost/protected', $session->get('_security.main.target_path'));
    }

    /**
     * The deep link, end to end.
     *
     * A user follows a link to a page they cannot see yet, logs in through the
     * identity provider, and lands on the page they asked for — not on a default.
     * Both halves are needed and neither is enough: the firewall saves the target
     * when the entry point fires, and the authenticator reads it back on success.
     */
    public function testADeepLinkSurvivesTheLoginRoundTrip(): void
    {
        $deepLink = Request::create('/protected/report/7');
        $session = new Session(new MockArraySessionStorage());
        $deepLink->setSession($session);

        // Leg one: denied, sent to the login flow, target remembered.
        $response = $this->kernel->handle($deepLink, catch: true);
        $this->assertSame(ConsumerAuthenticator::LOGIN_PATH, $response->headers->get('Location'));

        // Leg two: the same session, now arriving back from the identity provider.
        $authenticator = $this->kernel->getContainer()->get(ConsumerAuthenticator::class);
        $this->assertInstanceOf(ConsumerAuthenticator::class, $authenticator);

        $callback = Request::create('/callback_uri?state=s&code=c');
        $callback->setSession($session);

        $success = $authenticator->onAuthenticationSuccess(
            $callback,
            new PreAuthenticatedToken(new TestUser('someone@example.com'), 'main'),
            'main'
        );

        $this->assertInstanceOf(RedirectResponse::class, $success);
        $this->assertSame('http://localhost/protected/report/7', $success->getTargetUrl());
    }

    /**
     * Nothing was requested, so there is nothing to return to: a user who went
     * straight to the login link gets the application's default.
     */
    public function testWithoutARequestedPageTheFallbackIsUsed(): void
    {
        $authenticator = $this->kernel->getContainer()->get(ConsumerAuthenticator::class);
        $this->assertInstanceOf(ConsumerAuthenticator::class, $authenticator);

        $callback = Request::create('/callback_uri?state=s&code=c');
        $callback->setSession(new Session(new MockArraySessionStorage()));

        $success = $authenticator->onAuthenticationSuccess(
            $callback,
            new PreAuthenticatedToken(new TestUser('someone@example.com'), 'main'),
            'main'
        );

        $this->assertInstanceOf(RedirectResponse::class, $success);
        $this->assertSame(ConsumerAuthenticator::FALLBACK_PATH, $success->getTargetUrl());
    }
}

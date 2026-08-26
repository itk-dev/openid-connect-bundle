<?php

namespace ItkDev\OpenIdConnectBundle\Tests\Security;

use ItkDev\OpenIdConnectBundle\EventSubscriber\AuthenticationAuditSubscriber;
use ItkDev\OpenIdConnectBundle\Exception\AuthenticationFailedException;
use ItkDev\OpenIdConnectBundle\Exception\ProviderErrorException;
use ItkDev\OpenIdConnectBundle\Tests\ItkDevOpenIdConnectBundleTestingKernel;
use ItkDev\OpenIdConnectBundle\Tests\RestoresExceptionHandlers;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * The other loop, reproduced through a real firewall.
 *
 * RFC 6749 §4.1.2.1: a provider that refuses redirects back with `error` and
 * `state` and no `code`. While `supports()` required a `code`, that request was
 * not a callback at all: the firewall answered it, the entry point asked the
 * provider again, and the provider refused again — captured in production against
 * Azure AD B2C as dozens of rounds between the login route and the callback path,
 * with nothing logged and the one-time session values never spent.
 *
 * The sibling {@see FailedCallbackDoesNotLoopTest} covers the callback that
 * arrives with a `code` and fails validation. This one covers the callback that
 * never had a `code` to begin with.
 */
class ProviderErrorCallbackDoesNotLoopTest extends TestCase
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
     * A refusal that belongs to the login this browser started: the state matches.
     */
    private function refusedCallback(string $query = 'state=the-real-state&error=access_denied&error_description=User+cancelled'): Request
    {
        $request = Request::create('/callback_uri?'.$query);
        $request->setSession($this->startedLogin());

        return $request;
    }

    private function startedLogin(): Session
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('oauth2provider', 'test_provider_1');
        $session->set('oauth2state', 'the-real-state');
        $session->set('oauth2nonce', 'the-real-nonce');

        return $session;
    }

    /**
     * Guards against the whole test passing vacuously, exactly as the sibling does:
     * an unroutable path or a firewall that does not match gives a non-redirect too.
     */
    private function assertTheAuthenticatorHandledTheCallback(Request $request): void
    {
        $this->assertSame(
            'test_provider_1',
            $request->attributes->get(AuthenticationAuditSubscriber::PROVIDER_ATTRIBUTE),
            'The request never reached validateClaims(), so this test proves nothing.'
        );
    }

    /**
     * The assertion the production capture is about. A redirect here is the loop.
     */
    public function testAProviderErrorCallbackIsNotAnsweredWithARedirect(): void
    {
        $request = $this->refusedCallback();
        $response = $this->kernel->handle($request, catch: true);

        $this->assertTheAuthenticatorHandledTheCallback($request);
        $this->assertNull(
            $response->headers->get('Location'),
            'A refused login was answered with a redirect: the firewall re-entered its entry point and the loop is back.'
        );
        $this->assertSame(
            Response::HTTP_FORBIDDEN,
            $response->getStatusCode(),
            'A user who declined to log in has not caused a server error.'
        );
    }

    public function testAProviderErrorConsumesTheOneTimeSessionValues(): void
    {
        $request = $this->refusedCallback();
        $session = $request->getSession();

        $this->kernel->handle($request, catch: true);

        $this->assertTheAuthenticatorHandledTheCallback($request);
        $this->assertFalse($session->has('oauth2provider'));
        $this->assertFalse($session->has('oauth2state'));
        $this->assertFalse($session->has('oauth2nonce'), 'The nonce is unread on this path but still spent');
    }

    /**
     * The type has to survive the trip through the firewall intact, or the status
     * and the error code never reach the application that renders the page.
     */
    public function testTheProviderErrorReachesTheApplicationIntact(): void
    {
        $request = $this->refusedCallback();

        try {
            $this->kernel->handle($request, catch: false);
            $this->fail('A refused login should not be handled silently.');
        } catch (ProviderErrorException $exception) {
            $this->assertTheAuthenticatorHandledTheCallback($request);
            $this->assertSame('access_denied', $exception->getError());
            $this->assertSame('User cancelled', $exception->getErrorDescription());
            $this->assertSame(Response::HTTP_FORBIDDEN, $exception->getStatusCode());

            // The security ExceptionListener walks the whole chain, so a single
            // AuthenticationException anywhere beneath this would rebuild the loop.
            for ($cause = $exception; null !== $cause; $cause = $cause->getPrevious()) {
                $this->assertNotInstanceOf(AuthenticationException::class, $cause);
            }
        }
    }

    /**
     * A forged callback carries whatever text its sender chose. State is checked
     * before any of it is read, so none of it reaches the log, the exception or the
     * page — and the refusal is not reported as one.
     */
    public function testAForgedStateOnAnErrorCallbackTellsTheUserNothingTheAttackerWrote(): void
    {
        $request = $this->refusedCallback('state=does-not-match&error=access_denied&error_description=Call+0800+SCAM');

        try {
            $this->kernel->handle($request, catch: false);
            $this->fail('A forged callback should not be handled silently.');
        } catch (AuthenticationFailedException $exception) {
            $this->assertTheAuthenticatorHandledTheCallback($request);
            $this->assertNotInstanceOf(ProviderErrorException::class, $exception);
            $this->assertStringContainsString('Invalid state', $exception->getMessage());
            $this->assertStringNotContainsString('access_denied', $exception->getMessage());
            $this->assertStringNotContainsString('SCAM', $exception->getMessage());
        }

        // Still terminal, and still spent — on a fresh request, since the one above
        // consumed its session.
        $forged = $this->refusedCallback('state=does-not-match&error=access_denied&error_description=Call+0800+SCAM');
        $session = $forged->getSession();
        $response = $this->kernel->handle($forged, catch: true);

        $this->assertNull($response->headers->get('Location'));
        $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        $this->assertFalse($session->has('oauth2state'));
    }

    /**
     * Issue #63 restated in the new query shape: widening `supports()` must not put
     * back the ability to turn any page under the firewall into a failed login.
     */
    public function testAnErrorCallbackOnAStrayPathIsLeftToTheFirewall(): void
    {
        $request = Request::create('/protected?state=forged&error=access_denied');
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
     * `state` on its own is still not a callback. The callback path is outside
     * `access_control`, so the route answers it and the authenticator stays out.
     */
    public function testACallbackWithNeitherCodeNorErrorIsNotACallback(): void
    {
        $request = Request::create('/callback_uri?state=the-real-state');
        $request->setSession($this->startedLogin());

        $response = $this->kernel->handle($request, catch: true);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertNull(
            $request->attributes->get(AuthenticationAuditSubscriber::PROVIDER_ATTRIBUTE),
            'validateClaims() ran for a request carrying neither a code nor an error'
        );
    }
}

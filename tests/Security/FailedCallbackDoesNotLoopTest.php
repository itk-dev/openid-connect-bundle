<?php

namespace ItkDev\OpenIdConnectBundle\Tests\Security;

use ItkDev\OpenIdConnectBundle\EventSubscriber\AuthenticationAuditSubscriber;
use ItkDev\OpenIdConnectBundle\Exception\AuthenticationFailedException;
use ItkDev\OpenIdConnectBundle\Tests\ItkDevOpenIdConnectBundleTestingKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
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
    private ItkDevOpenIdConnectBundleTestingKernel $kernel;

    protected function setUp(): void
    {
        $this->kernel = new ItkDevOpenIdConnectBundleTestingKernel([
            __DIR__.'/../config/framework.yml',
            __DIR__.'/../config/framework_routing.yml',
            __DIR__.'/../config/security_consumer.yml',
            __DIR__.'/../config/itkdev_openid_connect.yml',
        ]);
        $this->kernel->boot();
    }

    /**
     * A callback whose state does not match the session: the shape of every
     * failure the outage produced, an expired client secret included.
     */
    private function failingCallback(): Request
    {
        $request = Request::create('/protected?state=does-not-match&code=some-code');
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
}

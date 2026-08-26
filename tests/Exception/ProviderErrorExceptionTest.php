<?php

namespace ItkDev\OpenIdConnectBundle\Tests\Exception;

use ItkDev\OpenIdConnectBundle\Exception\ProviderErrorException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The status is the whole reason this type implements `HttpExceptionInterface`:
 * a user who clicked Cancel gets a page, not an incident.
 */
class ProviderErrorExceptionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int}>
     */
    public static function statusCodeProvider(): iterable
    {
        // The user, or a policy standing in for them, said no.
        yield 'access_denied' => [ProviderErrorException::ACCESS_DENIED, Response::HTTP_FORBIDDEN];
        yield 'login_required' => ['login_required', Response::HTTP_FORBIDDEN];
        yield 'consent_required' => ['consent_required', Response::HTTP_FORBIDDEN];
        yield 'interaction_required' => ['interaction_required', Response::HTTP_FORBIDDEN];
        yield 'account_selection_required' => ['account_selection_required', Response::HTTP_FORBIDDEN];

        // The provider is having a bad day and says so.
        yield 'server_error' => ['server_error', Response::HTTP_SERVICE_UNAVAILABLE];
        yield 'temporarily_unavailable' => ['temporarily_unavailable', Response::HTTP_SERVICE_UNAVAILABLE];

        // Our request or our registration is wrong.
        yield 'invalid_request' => ['invalid_request', Response::HTTP_INTERNAL_SERVER_ERROR];
        yield 'unauthorized_client' => ['unauthorized_client', Response::HTTP_INTERNAL_SERVER_ERROR];
        yield 'invalid_scope' => ['invalid_scope', Response::HTTP_INTERNAL_SERVER_ERROR];
        // Providers invent their own; Azure AD B2C's policy errors look like this.
        yield 'a vendor code nobody has a rule for' => ['AADB2C90118', Response::HTTP_INTERNAL_SERVER_ERROR];
    }

    #[DataProvider('statusCodeProvider')]
    public function testTheStatusCodeSaysWhoIsAtFault(string $error, int $expected): void
    {
        $this->assertSame($expected, (new ProviderErrorException($error))->getStatusCode());
    }

    public function testTheMessageCarriesBothHalves(): void
    {
        $exception = new ProviderErrorException('access_denied', 'User cancelled');

        $this->assertSame('The identity provider refused the request: access_denied (User cancelled)', $exception->getMessage());
        $this->assertSame('access_denied', $exception->getError());
        $this->assertSame('User cancelled', $exception->getErrorDescription());
    }

    public function testTheMessageWithoutADescription(): void
    {
        $exception = new ProviderErrorException('access_denied');

        $this->assertSame('The identity provider refused the request: access_denied', $exception->getMessage());
        $this->assertNull($exception->getErrorDescription());
    }

    /**
     * Nothing here has a meaningful numeric code, and a consumer switching on one
     * would be reading a value the provider never sent.
     */
    public function testTheCodeIsZero(): void
    {
        $this->assertSame(0, (new ProviderErrorException('access_denied'))->getCode());
    }

    public function testACauseIsKept(): void
    {
        $cause = new \RuntimeException('underneath');

        $this->assertSame($cause, (new ProviderErrorException('access_denied', null, $cause))->getPrevious());
    }

    /**
     * Nothing here warrants a `WWW-Authenticate` or a `Retry-After`: the browser is
     * being shown a page, not asked to try again on its own.
     */
    public function testNoHeadersAreImposed(): void
    {
        $this->assertSame([], (new ProviderErrorException('access_denied'))->getHeaders());
    }
}

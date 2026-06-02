<?php

namespace ItkDev\OpenIdConnectBundle\Tests\Security;

use ItkDev\OpenIdConnect\Exception\ClaimsException;
use ItkDev\OpenIdConnect\Exception\OpenIdConnectExceptionInterface;
use ItkDev\OpenIdConnect\Exception\ValidationException;
use ItkDev\OpenIdConnect\Security\OpenIdConfigurationProvider;
use ItkDev\OpenIdConnectBundle\Security\OpenIdConfigurationProviderManager;
use ItkDev\OpenIdConnectBundle\Security\OpenIdLoginAuthenticator;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

class OpenIdLoginAuthenticatorTest extends TestCase
{
    private OpenIdLoginAuthenticator $authenticator;
    /** @var OpenIdConfigurationProviderManager&Stub */
    private OpenIdConfigurationProviderManager $stubProviderManager;

    protected function setUp(): void
    {
        $this->stubProviderManager = $this->createStub(OpenIdConfigurationProviderManager::class);

        $this->authenticator = new TestAuthenticator($this->stubProviderManager);
    }

    public function testSupports(): void
    {
        $request = new Request();

        $this->assertFalse($this->authenticator->supports($request));

        $request->query->set('state', 'abcd');
        $this->assertFalse($this->authenticator->supports($request));

        $request->query->set('code', 'xyz');
        $this->assertTrue($this->authenticator->supports($request));
    }

    public function testOnAuthenticationFailure(): void
    {
        $this->expectException(AuthenticationException::class);

        $exception = new AuthenticationException();

        $this->authenticator->onAuthenticationFailure(new Request(), $exception);
    }

    public function testValidateClaimsWrongState(): void
    {
        $request = new Request(query: ['state' => 'wrong_test_state']);
        $this->setSessionOnRequest($request);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid state');
        $this->authenticator->authenticate($request);
    }

    public function testValidateClaimsEmptyNonce(): void
    {
        $request = new Request(query: ['state' => 'test_state']);
        $this->setSessionOnRequest($request, nonce: null);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Nonce empty or not found');
        $this->authenticator->authenticate($request);
    }

    public function testValidateClaimsMissingCode(): void
    {
        $request = new Request(query: ['state' => 'test_state']);
        $this->setSessionOnRequest($request);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Missing or invalid code');
        $this->authenticator->authenticate($request);
    }

    public function testValidateClaimsCodeDoesNotValidate(): void
    {
        $cause = new ClaimsException('test message');
        $stubProvider = $this->createStub(OpenIdConfigurationProvider::class);
        $stubProvider->method('validateIdToken')->willThrowException($cause);
        $this->stubProviderManager->method('getProvider')->willReturn($stubProvider);

        $request = new Request(query: ['state' => 'test_state', 'code' => 'test_code']);
        $this->setSessionOnRequest($request);

        try {
            $this->authenticator->authenticate($request);
        } catch (ValidationException $thrown) {
            $this->assertSame('test message', $thrown->getMessage());
            $this->assertSame($cause, $thrown->getPrevious(), 'Original cause must be chained');
            $this->assertInstanceOf(
                OpenIdConnectExceptionInterface::class,
                $thrown->getPrevious(),
                'Wrapped cause must satisfy the library marker contract',
            );

            return;
        }
        $this->fail('Expected ValidationException');
    }

    public function testValidateClaimsSuccess(): void
    {
        $stubProvider = $this->createStub(OpenIdConfigurationProvider::class);

        $claims = new \stdClass();
        $claims->email = 'test@test.com';
        $claims->name = 'Test Tester';
        $stubProvider->method('validateIdToken')->willReturn($claims);

        $this->stubProviderManager->method('getProvider')->willReturn($stubProvider);

        $request = new Request(query: ['state' => 'test_state', 'code' => 'test_code']);
        $this->setSessionOnRequest($request);

        $passport = $this->authenticator->authenticate($request);

        $this->assertSame('test@test.com', $passport->getUser()->getUserIdentifier());
    }

    private function setSessionOnRequest(Request $request, ?string $nonce = 'test_nonce'): void
    {
        $stubSession = $this->createStub(SessionInterface::class);
        $map = [
            ['oauth2provider', 'test_provider_1'],
            ['oauth2state', 'test_state'],
            ['oauth2nonce', $nonce],
        ];
        $stubSession->method('remove')->willReturnMap($map);

        $request->setSession($stubSession);
    }
}

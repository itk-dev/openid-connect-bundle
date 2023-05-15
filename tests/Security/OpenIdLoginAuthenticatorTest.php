<?php

namespace ItkDev\OpenIdConnectBundle\Tests\Security;

use ItkDev\OpenIdConnect\Exception\ClaimsException;
use ItkDev\OpenIdConnect\Exception\ValidationException;
use ItkDev\OpenIdConnect\Security\OpenIdConfigurationProvider;
use ItkDev\OpenIdConnectBundle\Security\OpenIdConfigurationProviderManager;
use ItkDev\OpenIdConnectBundle\Security\OpenIdLoginAuthenticator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

class OpenIdLoginAuthenticatorTest extends TestCase
{
    private OpenIdLoginAuthenticator $authenticator;
    private $mockProviderManager;

    public function setup(): void
    {
        $this->mockProviderManager = $this->createMock(OpenIdConfigurationProviderManager::class);

        $this->authenticator = new TestAuthenticator($this->mockProviderManager);
    }

    public function testSupports(): void
    {
        $mockRequest = $this->createMock(Request::class);
        $mockRequest->query = new InputBag();

        $this->assertFalse($this->authenticator->supports($mockRequest));

        $mockRequest->query->set('state', 'abcd');
        $this->assertFalse($this->authenticator->supports($mockRequest));

        $mockRequest->query->set('code', 'xyz');
        $this->assertTrue($this->authenticator->supports($mockRequest));
    }

    public function testOnAuthenticationFailure(): void
    {
        $this->expectException(AuthenticationException::class);

        $stubRequest = $this->createStub(Request::class);
        $exception = new AuthenticationException();

        $this->authenticator->onAuthenticationFailure($stubRequest, $exception);
    }

    public function testValidateClaimsWrongState(): void
    {
        $mockRequest = $this->createMock(Request::class);

        $mockRequest->query = new InputBag(['state' => 'wrong_test_state']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid state');
        $this->authenticator->authenticate($mockRequest);
    }

    public function testValidateClaimsNoCode(): void
    {
        $mockRequest = $this->createMock(Request::class);

        $mockRequest->query = new InputBag(['state' => 'test_state']);

        $this->setupMockSessionOnMockRequest($mockRequest);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Missing code');
        $this->authenticator->authenticate($mockRequest);
    }

    public function testValidateClaimsCodeNotString(): void
    {
        $mockRequest = $this->createMock(Request::class);

        $mockRequest->query = new InputBag(['state' => 'test_state', 'code' => 42]);

        $this->setupMockSessionOnMockRequest($mockRequest);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Code not type string');
        $this->authenticator->authenticate($mockRequest);
    }

    public function testValidateClaimsCodeDoesNotValidate(): void
    {
        $mockProvider = $this->createMock(OpenIdConfigurationProvider::class);
        $mockProvider->method('validateIdToken')->willThrowException(new ClaimsException('test message'));
        $this->mockProviderManager->method('getProvider')->willReturn($mockProvider);

        $mockRequest = $this->createMock(Request::class);
        $mockRequest->query = new InputBag(['state' => 'test_state', 'code' => 'test_code']);

        $this->setupMockSessionOnMockRequest($mockRequest);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('test message');
        $this->authenticator->authenticate($mockRequest);
    }

    public function testValidateClaimsSuccess(): void
    {
        $mockProvider = $this->createMock(OpenIdConfigurationProvider::class);

        $claims = new \stdClass();
        $claims->email = 'test@test.com';
        $claims->name = 'Test Tester';
        $mockProvider->method('validateIdToken')->willReturn($claims);

        $this->mockProviderManager->method('getProvider')->willReturn($mockProvider);

        $mockRequest = $this->createMock(Request::class);
        $mockRequest->query = new InputBag(['state' => 'test_state', 'code' => 'test_code']);

        $this->setupMockSessionOnMockRequest($mockRequest);

        $passport = $this->authenticator->authenticate($mockRequest);

        $this->assertSame('test@test.com', $passport->getUser()->getUserIdentifier());
    }

    private function setupMockSessionOnMockRequest(MockObject $mockRequest)
    {
        $mockSession = $this->createMock(SessionInterface::class);
        $map = [
            ['oauth2provider', 'test_provider_1'],
            ['oauth2state', 'test_state'],
            ['oauth2nonce', 'test_nonce'],
        ];
        $mockSession->method('remove')->will($this->returnValueMap($map));

        $mockRequest->method('getSession')->willReturn($mockSession);
    }
}

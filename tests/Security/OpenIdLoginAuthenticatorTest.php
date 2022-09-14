<?php

namespace ItkDev\OpenIdConnectBundle\Tests\Security;

use ItkDev\OpenIdConnect\Exception\ClaimsException;
use ItkDev\OpenIdConnect\Exception\ValidationException;
use ItkDev\OpenIdConnect\Security\OpenIdConfigurationProvider;
use ItkDev\OpenIdConnectBundle\Security\OpenIdConfigurationProviderManager;
use ItkDev\OpenIdConnectBundle\Security\OpenIdLoginAuthenticator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

class OpenIdLoginAuthenticatorTest extends TestCase
{
    private OpenIdLoginAuthenticator $authenticator;
    private $mockProviderManager;

    public function setup(): void
    {
        $this->mockProviderManager = $this->createMock(OpenIdConfigurationProviderManager::class);

        $mockSession = $this->createMock(SessionInterface::class);
        $map = [
            ['oauth2provider', 'test_provider_1'],
            ['oauth2state', 'test_state'],
            ['oauth2nonce', 'test_nonce'],
        ];
        $mockSession->method('remove')->will($this->returnValueMap($map));

        $mockRequestStack = $this->createMock(RequestStack::class);
        $mockRequestStack->method('getSession')->willReturn($mockSession);

        $this->authenticator = new TestAuthenticator($this->mockProviderManager, $mockRequestStack);
    }

    public function testSupports(): void
    {
        $mockRequest = $this->createMock(Request::class);
        $mockRequest->query = new InputBag();

        $this->assertFalse($this->authenticator->supports($mockRequest));

        $mockRequest->query->set('state', 'abcd');
        $this->assertFalse($this->authenticator->supports($mockRequest));

        $mockRequest->query->set('id_token', 'xyz');
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

    public function testValidateClaimsNoToken(): void
    {
        $mockRequest = $this->createMock(Request::class);

        $mockRequest->query = new InputBag(['state' => 'test_state']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Id token not found');
        $this->authenticator->authenticate($mockRequest);
    }

    public function testValidateClaimsTokenNotString(): void
    {
        $mockRequest = $this->createMock(Request::class);

        $mockRequest->query = new InputBag(['state' => 'test_state', 'id_token' => 42]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Id token not type string');
        $this->authenticator->authenticate($mockRequest);
    }

    public function testValidateClaimsTokenDoesNotValidate(): void
    {
        $mockProvider = $this->createMock(OpenIdConfigurationProvider::class);
        $mockProvider->method('validateIdToken')->willThrowException(new ClaimsException('test message'));
        $this->mockProviderManager->method('getProvider')->willReturn($mockProvider);

        $mockRequest = $this->createMock(Request::class);
        $mockRequest->query = new InputBag(['state' => 'test_state', 'id_token' => 'test_token']);

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
        $mockRequest->query = new InputBag(['state' => 'test_state', 'id_token' => 'test_token']);

        $passport = $this->authenticator->authenticate($mockRequest);

        $this->assertSame('test@test.com', $passport->getUser()->getUserIdentifier());
    }
}

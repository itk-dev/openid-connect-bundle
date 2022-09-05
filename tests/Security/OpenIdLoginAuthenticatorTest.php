<?php

namespace ItkDev\OpenIdConnectBundle\Tests\Security;

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

    public function setup(): void
    {
        $mockProviderManager = $this->createMock(OpenIdConfigurationProviderManager::class);
        $mockSession = $this->createMock(SessionInterface::class);
        $mockRequestStack = $this->createMock(RequestStack::class);
        $mockRequestStack->method('getSession')->willReturn($mockSession);

        $this->authenticator = new TestAuthenticator($mockProviderManager, $mockRequestStack);
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
}

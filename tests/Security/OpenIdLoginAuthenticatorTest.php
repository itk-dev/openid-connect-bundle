<?php

namespace ItkDev\OpenIdConnectBundle\Tests\Security;

use ItkDev\OpenIdConnect\Security\OpenIdConfigurationProvider;
use ItkDev\OpenIdConnectBundle\Security\OpenIdConfigurationProviderManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;

class OpenIdLoginAuthenticatorTest extends TestCase
{
    private $authenticator;

    public function setup(): void
    {
        $mockProviderManager = $this->createMock(OpenIdConfigurationProviderManager::class);
        $mockSession = $this->createMock(SessionInterface::class);

        $this->authenticator = new TestAuthenticator($mockProviderManager, $mockSession);
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

    public function testCheckCredentials(): void
    {
        $stubUser = $this->createStub(UserInterface::class);

        $this->assertTrue($this->authenticator->checkCredentials(null, $stubUser));
    }

    public function testOnAuthenticationFailure(): void
    {
        $this->expectException(AuthenticationException::class);

        $stubRequest = $this->createStub(Request::class);
        $exception = new AuthenticationException();

        $this->authenticator->onAuthenticationFailure($stubRequest, $exception);
    }

    public function testSupportsRememberMe(): void
    {
        $this->assertFalse($this->authenticator->supportsRememberMe());
    }
}

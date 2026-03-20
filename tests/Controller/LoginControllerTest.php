<?php

namespace ItkDev\OpenIdConnectBundle\Tests\Controller;

use ItkDev\OpenIdConnect\Security\OpenIdConfigurationProvider;
use ItkDev\OpenIdConnectBundle\Controller\LoginController;
use ItkDev\OpenIdConnectBundle\Security\OpenIdConfigurationProviderManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

class LoginControllerTest extends TestCase
{
    private LoginController $loginController;

    public function setUp(): void
    {
        parent::setUp();

        $mockProvider = $this->createMock(OpenIdConfigurationProvider::class);
        $mockProvider
            ->expects($this->exactly(1))
            ->method('generateNonce')
            ->willReturn('1234');
        $mockProvider
            ->expects($this->exactly(1))
            ->method('generateState')
            ->willReturn('abcd');
        $mockProvider
            ->expects($this->exactly(1))
            ->method('getAuthorizationUrl')
            ->with(['state' => 'abcd', 'nonce' => '1234', 'response_type' => 'code', 'scope' => 'openid email profile'])
            ->willReturn('https://test.com');

        $mockProviderManager = $this->createMock(OpenIdConfigurationProviderManager::class);
        $mockProviderManager
            ->expects($this->once())
            ->method('getProvider')
            ->with('test')
            ->willReturn($mockProvider);

        $this->loginController = new LoginController($mockProviderManager);
    }

    public function testLogin(): void
    {
        $stubRequest = $this->createStub(Request::class);
        $stubRequest->query = new InputBag(['provider' => 'test']);
        $mockSession = $this->createMock(SessionInterface::class);
        $matcher = $this->exactly(3);
        $mockSession
            ->expects($matcher)
            ->method('set')->willReturnCallback(function (...$parameters) use ($matcher) {
                if (1 === $matcher->numberOfInvocations()) {
                    $this->assertEquals('oauth2provider', $parameters[0]);
                    $this->assertEquals('test', $parameters[1]);
                }
                if (2 === $matcher->numberOfInvocations()) {
                    $this->assertEquals('oauth2state', $parameters[0]);
                    $this->assertEquals('abcd', $parameters[1]);
                }
                if (3 === $matcher->numberOfInvocations()) {
                    $this->assertEquals('oauth2nonce', $parameters[0]);
                    $this->assertEquals('1234', $parameters[1]);
                }
            });

        $response = $this->loginController->login($stubRequest, $mockSession, 'test');
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('https://test.com', $response->getTargetUrl());
    }
}

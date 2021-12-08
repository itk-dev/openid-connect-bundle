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
    private $loginController;

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
            ->with(['state' => 'abcd', 'nonce' => '1234'])
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
        $mockRequest = $this->createMock(Request::class);
        $mockRequest->query = new InputBag(['provider' => 'test']);
        $mockSession = $this->createMock(SessionInterface::class);
        $mockSession
            ->expects($this->exactly(3))
            ->method('set')
            ->withConsecutive(
                [$this->equalTo('oauth2provider'), $this->equalTo('test')],
                [$this->equalTo('oauth2state'), $this->equalTo('abcd')],
                [$this->equalTo('oauth2nonce'), $this->equalTo('1234')]
            );

        $response = $this->loginController->login($mockRequest, $mockSession, 'test');
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('https://test.com', $response->getTargetUrl());
    }
}

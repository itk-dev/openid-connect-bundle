<?php

namespace ItkDev\OpenIdConnectBundle\Tests\Controller;

use ItkDev\OpenIdConnect\Exception\CacheException;
use ItkDev\OpenIdConnect\Exception\HttpException;
use ItkDev\OpenIdConnect\Exception\JsonException;
use ItkDev\OpenIdConnect\Security\OpenIdConfigurationProvider;
use ItkDev\OpenIdConnectBundle\Controller\LoginController;
use ItkDev\OpenIdConnectBundle\Exception\InvalidProviderException;
use ItkDev\OpenIdConnectBundle\Security\OpenIdConfigurationProviderManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class LoginControllerTest extends TestCase
{
    public function testLogin(): void
    {
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

        $controller = $this->createController($mockProvider);

        $request = new Request(query: ['provider' => 'test']);
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

        $response = $controller->login($request, $mockSession, 'test');
        $this->assertSame('https://test.com', $response->getTargetUrl());
    }

    public function testUnknownProviderKeyMapsTo404(): void
    {
        $cause = new InvalidProviderException('Invalid provider: bogus');

        $mockProviderManager = $this->createMock(OpenIdConfigurationProviderManager::class);
        $mockProviderManager
            ->expects($this->once())
            ->method('getProvider')
            ->with('bogus')
            ->willThrowException($cause);

        $controller = new LoginController($mockProviderManager);

        try {
            $controller->login(new Request(), $this->createStub(SessionInterface::class), 'bogus');
        } catch (NotFoundHttpException $thrown) {
            $this->assertSame(404, $thrown->getStatusCode());
            $this->assertStringContainsString('bogus', $thrown->getMessage());
            $this->assertSame($cause, $thrown->getPrevious(), 'Original exception must be chained');

            return;
        }
        $this->fail('Expected NotFoundHttpException');
    }

    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function upstreamFailureProvider(): iterable
    {
        yield 'metadata HTTP timeout' => [new HttpException('Connection timed out')];
        yield 'metadata malformed JSON' => [new JsonException('Syntax error')];
        yield 'cache layer failure' => [new CacheException('Cache backend unreachable')];
    }

    #[DataProvider('upstreamFailureProvider')]
    public function testUpstreamFailureMapsTo503(\Throwable $cause): void
    {
        $stubProvider = $this->createStub(OpenIdConfigurationProvider::class);
        $stubProvider->method('generateNonce')->willReturn('1234');
        $stubProvider->method('generateState')->willReturn('abcd');
        $stubProvider->method('getAuthorizationUrl')->willThrowException($cause);

        $controller = $this->createController($stubProvider);

        try {
            $controller->login(new Request(), $this->createStub(SessionInterface::class), 'test');
        } catch (ServiceUnavailableHttpException $thrown) {
            $this->assertSame(503, $thrown->getStatusCode());
            $this->assertStringContainsString('test', $thrown->getMessage());
            $this->assertSame($cause, $thrown->getPrevious(), 'Original exception must be chained');

            return;
        }
        $this->fail('Expected ServiceUnavailableHttpException');
    }

    private function createController(OpenIdConfigurationProvider $provider): LoginController
    {
        $mockProviderManager = $this->createMock(OpenIdConfigurationProviderManager::class);
        $mockProviderManager
            ->expects($this->once())
            ->method('getProvider')
            ->with('test')
            ->willReturn($provider);

        return new LoginController($mockProviderManager);
    }
}

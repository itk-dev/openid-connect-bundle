<?php

namespace ItkDev\OpenIdConnectBundle\Tests\Security;

use ItkDev\OpenIdConnectBundle\Exception\CacheException;
use ItkDev\OpenIdConnectBundle\Exception\TokenNotFoundException;
use ItkDev\OpenIdConnectBundle\Exception\UsernameDoesNotExistException;
use ItkDev\OpenIdConnectBundle\Security\CliLoginTokenAuthenticator;
use ItkDev\OpenIdConnectBundle\Util\CliLoginHelper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

class CliLoginTokenAuthenticatorTest extends TestCase
{
    private CliLoginTokenAuthenticator $authenticator;
    private CliLoginHelper $stubCliLoginHelper;
    private UrlGeneratorInterface $stubRouter;

    protected function setUp(): void
    {
        $this->stubCliLoginHelper = $this->createStub(CliLoginHelper::class);
        $this->stubRouter = $this->createStub(UrlGeneratorInterface::class);

        $this->authenticator = new CliLoginTokenAuthenticator(
            $this->stubCliLoginHelper,
            'cli_login_route',
            $this->stubRouter
        );
    }

    public function testSupportsWithLoginToken(): void
    {
        $request = $this->createStub(Request::class);
        $request->query = new InputBag(['loginToken' => 'some-token']);

        $this->assertTrue($this->authenticator->supports($request));
    }

    public function testSupportsWithoutLoginToken(): void
    {
        $request = $this->createStub(Request::class);
        $request->query = new InputBag();

        $this->assertFalse($this->authenticator->supports($request));
    }

    public function testAuthenticateWithEmptyToken(): void
    {
        $request = $this->createStub(Request::class);
        $request->query = new InputBag(['loginToken' => '']);

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('No login token provided');

        $this->authenticator->authenticate($request);
    }

    public function testAuthenticateWithInvalidToken(): void
    {
        $this->stubCliLoginHelper
            ->method('getUsername')
            ->willThrowException(new TokenNotFoundException('Token does not exist'));

        $request = $this->createStub(Request::class);
        $request->query = new InputBag(['loginToken' => 'invalid-token']);

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('Cannot get username');

        $this->authenticator->authenticate($request);
    }

    public function testAuthenticateWithCacheException(): void
    {
        $this->stubCliLoginHelper
            ->method('getUsername')
            ->willThrowException(new CacheException('Cache error'));

        $request = $this->createStub(Request::class);
        $request->query = new InputBag(['loginToken' => 'some-token']);

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('Cannot get username');

        $this->authenticator->authenticate($request);
    }

    public function testAuthenticateWithNullUsername(): void
    {
        $this->stubCliLoginHelper
            ->method('getUsername')
            ->willReturn(null);

        $request = $this->createStub(Request::class);
        $request->query = new InputBag(['loginToken' => 'some-token']);

        $this->expectException(UsernameDoesNotExistException::class);

        $this->authenticator->authenticate($request);
    }

    public function testAuthenticateSuccess(): void
    {
        $this->stubCliLoginHelper
            ->method('getUsername')
            ->willReturn('test@example.com');

        $request = $this->createStub(Request::class);
        $request->query = new InputBag(['loginToken' => 'valid-token']);

        $passport = $this->authenticator->authenticate($request);

        $userBadge = $passport->getBadge(UserBadge::class);
        $this->assertSame('test@example.com', $userBadge->getUserIdentifier());
    }

    public function testOnAuthenticationSuccess(): void
    {
        $this->stubRouter
            ->method('generate')
            ->willReturn('/login');

        $request = $this->createStub(Request::class);
        $token = $this->createStub(TokenInterface::class);

        $response = $this->authenticator->onAuthenticationSuccess($request, $token, 'main');

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/login', $response->getTargetUrl());
    }

    public function testOnAuthenticationFailure(): void
    {
        $request = $this->createStub(Request::class);
        $exception = new AuthenticationException();

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Error occurred validating login token');

        $this->authenticator->onAuthenticationFailure($request, $exception);
    }
}

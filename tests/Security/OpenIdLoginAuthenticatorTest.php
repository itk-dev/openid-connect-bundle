<?php

namespace ItkDev\OpenIdConnectBundle\Tests\Security;

use ItkDev\OpenIdConnect\Exception\ClaimsException;
use ItkDev\OpenIdConnect\Exception\HttpException;
use ItkDev\OpenIdConnect\Exception\ValidationException;
use ItkDev\OpenIdConnect\Security\OpenIdConfigurationProvider;
use ItkDev\OpenIdConnectBundle\Security\OpenIdConfigurationProviderManager;
use ItkDev\OpenIdConnectBundle\Security\OpenIdLoginAuthenticator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

class OpenIdLoginAuthenticatorTest extends TestCase
{
    private OpenIdLoginAuthenticator $authenticator;
    private OpenIdConfigurationProviderManager $stubProviderManager;

    protected function setUp(): void
    {
        $this->stubProviderManager = $this->createStub(OpenIdConfigurationProviderManager::class);

        $this->authenticator = new TestAuthenticator($this->stubProviderManager);
    }

    public function testSupports(): void
    {
        $request = $this->createStub(Request::class);
        $request->query = new InputBag();

        $this->assertFalse($this->authenticator->supports($request));

        $request->query->set('state', 'abcd');
        $this->assertFalse($this->authenticator->supports($request));

        $request->query->set('code', 'xyz');
        $this->assertTrue($this->authenticator->supports($request));
    }

    public function testOnAuthenticationFailurePreservesCause(): void
    {
        $causeMessage = 'Original cause message';
        $stubRequest = $this->createStub(Request::class);
        $cause = new AuthenticationException($causeMessage);

        try {
            $this->authenticator->onAuthenticationFailure($stubRequest, $cause);
        } catch (AuthenticationException $thrown) {
            $this->assertSame($cause, $thrown->getPrevious(), 'Original exception must be chained as previous');
            $this->assertStringContainsString($causeMessage, $thrown->getMessage(), 'Cause message must be preserved for logs');
        }
    }

    public function testValidateClaimsWrongState(): void
    {
        $request = $this->createStub(Request::class);
        $request->query = new InputBag(['state' => 'wrong_test_state']);

        $this->setupStubSessionOnRequest($request);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid state');
        $this->authenticator->authenticate($request);
    }

    public function testValidateClaimsEmptyNonce(): void
    {
        $request = $this->createStub(Request::class);
        $request->query = new InputBag(['state' => 'test_state']);

        $this->setupStubSessionOnRequest($request, nonce: null);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Nonce empty or not found');
        $this->authenticator->authenticate($request);
    }

    public function testValidateClaimsMissingCode(): void
    {
        $request = $this->createStub(Request::class);
        $request->query = new InputBag(['state' => 'test_state']);

        $this->setupStubSessionOnRequest($request);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Missing or invalid code');
        $this->authenticator->authenticate($request);
    }

    public function testValidateClaimsBubblesClaimsExceptionUnchanged(): void
    {
        $claimsMessage = 'ID token has incorrect nonce';
        $stubProvider = $this->createStub(OpenIdConfigurationProvider::class);
        $stubProvider->method('validateIdToken')->willThrowException(new ClaimsException($claimsMessage));
        $this->stubProviderManager->method('getProvider')->willReturn($stubProvider);

        $request = $this->createStub(Request::class);
        $request->query = new InputBag(['state' => 'test_state', 'code' => 'test_code']);

        $this->setupStubSessionOnRequest($request);

        // ClaimsException must reach the caller as itself, not collapsed into the
        // base ItkOpenIdConnectException or ValidationException, so consumers can
        // distinguish a bad nonce/issuer/audience from a network failure.
        $this->expectException(ClaimsException::class);
        $this->expectExceptionMessage($claimsMessage);
        $this->authenticator->authenticate($request);
    }

    public function testValidateClaimsBubblesHttpExceptionUnchanged(): void
    {
        $stubProvider = $this->createStub(OpenIdConfigurationProvider::class);
        $stubProvider->method('getIdToken')->willThrowException(new HttpException('Connection timed out'));
        $this->stubProviderManager->method('getProvider')->willReturn($stubProvider);

        $request = $this->createStub(Request::class);
        $request->query = new InputBag(['state' => 'test_state', 'code' => 'test_code']);

        $this->setupStubSessionOnRequest($request);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Connection timed out');
        $this->authenticator->authenticate($request);
    }

    public function testValidateClaimsSuccess(): void
    {
        $stubProvider = $this->createStub(OpenIdConfigurationProvider::class);

        $claims = new \stdClass();
        $claims->email = 'test@test.com';
        $claims->name = 'Test Tester';
        $stubProvider->method('validateIdToken')->willReturn($claims);

        $this->stubProviderManager->method('getProvider')->willReturn($stubProvider);

        $request = $this->createStub(Request::class);
        $request->query = new InputBag(['state' => 'test_state', 'code' => 'test_code']);

        $this->setupStubSessionOnRequest($request);

        $passport = $this->authenticator->authenticate($request);

        $this->assertSame('test@test.com', $passport->getUser()->getUserIdentifier());
    }

    private function setupStubSessionOnRequest(Request $request, ?string $nonce = 'test_nonce'): void
    {
        $stubSession = $this->createStub(SessionInterface::class);
        $map = [
            ['oauth2provider', 'test_provider_1'],
            ['oauth2state', 'test_state'],
            ['oauth2nonce', $nonce],
        ];
        $stubSession->method('remove')->willReturnMap($map);

        $request->method('getSession')->willReturn($stubSession);
    }
}

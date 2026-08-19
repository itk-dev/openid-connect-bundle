<?php

namespace ItkDev\OpenIdConnectBundle\Tests\Security;

use ItkDev\OpenIdConnect\Exception\ClaimsException;
use ItkDev\OpenIdConnect\Exception\OpenIdConnectExceptionInterface;
use ItkDev\OpenIdConnect\Exception\ValidationException;
use ItkDev\OpenIdConnect\Security\OpenIdConfigurationProvider;
use ItkDev\OpenIdConnectBundle\EventSubscriber\AuthenticationAuditSubscriber;
use ItkDev\OpenIdConnectBundle\Exception\AuthenticationFailedException;
use ItkDev\OpenIdConnectBundle\Exception\InvalidProviderException;
use ItkDev\OpenIdConnectBundle\Exception\OpenIdConnectBundleExceptionInterface;
use ItkDev\OpenIdConnectBundle\Security\OpenIdConfigurationProviderManager;
use ItkDev\OpenIdConnectBundle\Security\OpenIdLoginAuthenticator;
use ItkDev\OpenIdConnectBundle\Tests\TestLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

class OpenIdLoginAuthenticatorTest extends TestCase
{
    private OpenIdLoginAuthenticator $authenticator;
    /** @var OpenIdConfigurationProviderManager&Stub */
    private OpenIdConfigurationProviderManager $stubProviderManager;
    private TestLogger $logger;

    protected function setUp(): void
    {
        $this->stubProviderManager = $this->createStub(OpenIdConfigurationProviderManager::class);
        $this->logger = new TestLogger();

        $this->authenticator = new TestAuthenticator($this->stubProviderManager);
        // Symfony calls setLogger() on autoconfigured LoggerAwareInterface services.
        $this->authenticator->setLogger($this->logger);
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

    /**
     * The assertion that encodes "the loop cannot come back".
     *
     * Everything else here is detail; what matters is the type. Symfony's security
     * ExceptionListener catches `AuthenticationException` and re-enters the entry
     * point, which for this authenticator is another redirect to the identity
     * provider. Throwing something outside that hierarchy is what stops a failing
     * callback from being retried forever.
     */
    public function testOnAuthenticationFailureThrowsOutsideTheSecurityHierarchy(): void
    {
        $cause = new AuthenticationException('Original cause message');

        // Caught as Throwable on purpose: catching the expected type first would
        // narrow it statically and make the assertions below tautologies, which is
        // precisely the mistake that would let the type quietly regress.
        try {
            $this->authenticator->onAuthenticationFailure(new Request(), $cause);
            $this->fail('Expected AuthenticationFailedException');
        } catch (\Throwable $thrown) {
            $this->assertNotInstanceOf(
                AuthenticationException::class,
                $thrown,
                'An AuthenticationException would be caught by the firewall and turned back into a redirect to the identity provider',
            );
            $this->assertInstanceOf(
                OpenIdConnectBundleExceptionInterface::class,
                $thrown,
                'Consumers catch the bundle marker, per ADR 001',
            );
            $this->assertInstanceOf(AuthenticationFailedException::class, $thrown);

            // Not chained, even though ADR 001 asks for a cause: the security
            // ExceptionListener walks the whole $previous chain, so an
            // AuthenticationException reachable through it is caught and turned back
            // into a redirect exactly as if it had been thrown directly. The message
            // carries the reason instead.
            $this->assertNull($thrown->getPrevious(), 'An AuthenticationException must not be reachable through the chain');
            $this->assertStringContainsString('Original cause message', $thrown->getMessage(), 'Cause message must be preserved for logs');

            // Deliberately no record: the framework already logs the original
            // exception, validateClaims() logged the specific reason, and the
            // application logs whatever escapes.
            $this->assertSame([], $this->logger->records);
        }
    }

    /**
     * The chain is dropped only as far as it has to be. A library exception below
     * the AuthenticationException is what says *why* the callback failed, and it
     * is safe to keep because the listener does not act on it.
     */
    #[DataProvider('causeChainProvider')]
    public function testACauseOutsideTheSecurityHierarchyIsKept(\Throwable $cause, ?\Throwable $expected): void
    {
        try {
            $this->authenticator->onAuthenticationFailure(new Request(), new AuthenticationException('Sanitised by the firewall', 0, $cause));
            $this->fail('Expected AuthenticationFailedException');
        } catch (\Throwable $thrown) {
            $this->assertSame($expected, $thrown->getPrevious());
        }
    }

    /**
     * @return iterable<string, array{\Throwable, ?\Throwable}>
     */
    public static function causeChainProvider(): iterable
    {
        $root = new ValidationException('Invalid state');

        yield 'library cause is kept' => [$root, $root];
        // The firewall wraps more than once in places, so one skip is not enough.
        yield 'reached past nested security exceptions' => [new AuthenticationException('inner', 0, $root), $root];
        yield 'nothing left to keep' => [new AuthenticationException('inner'), null];
    }

    public function testUnknownProviderIsLoggedAndRethrown(): void
    {
        $cause = new InvalidProviderException('Invalid provider: test_provider_1');
        $this->stubProviderManager->method('getProvider')->willThrowException($cause);

        $request = new Request(query: ['state' => 'test_state', 'code' => 'test_code']);
        $this->setSessionOnRequest($request);

        try {
            $this->authenticator->authenticate($request);
        } catch (InvalidProviderException $thrown) {
            $this->assertSame($cause, $thrown, 'The provider exception must propagate unchanged');

            $record = $this->logger->singleRecord();
            $this->assertSame(LogLevel::ERROR, $record['level'], 'A misconfigured or lost provider needs operator attention');
            $this->assertStringContainsString('provider not configured', $record['message']);
            $this->assertSame('test_provider_1', $record['context']['provider'] ?? null);
            $this->assertSame($cause, $record['context']['exception'] ?? null);

            return;
        }
        $this->fail('Expected InvalidProviderException');
    }

    public function testProviderKeyIsPublishedOnTheRequestForTheAuditTrail(): void
    {
        // The audit subscriber can only attribute a login to a provider because
        // validateClaims() puts the key on the request: it is a local there, the
        // session entry is removed destructively, and no security event carries it.
        $stubProvider = $this->createStub(OpenIdConfigurationProvider::class);
        $claims = new \stdClass();
        $claims->email = 'test@example.org';
        $claims->name = 'Test Tester';
        $stubProvider->method('validateIdToken')->willReturn($claims);
        $this->stubProviderManager->method('getProvider')->willReturn($stubProvider);

        $request = new Request(query: ['state' => 'test_state', 'code' => 'test_code']);
        $this->setSessionOnRequest($request);

        $this->authenticator->authenticate($request);

        $this->assertSame('test_provider_1', $request->attributes->get(AuthenticationAuditSubscriber::PROVIDER_ATTRIBUTE));
    }

    public function testProviderKeyIsPublishedEvenWhenValidationFails(): void
    {
        // Failure records carry the provider, so the attribute has to be set
        // before any of the validation steps can throw.
        $request = new Request(query: ['state' => 'wrong_test_state']);
        $this->setSessionOnRequest($request);

        try {
            $this->authenticator->authenticate($request);
        } catch (ValidationException) {
            $this->assertSame('test_provider_1', $request->attributes->get(AuthenticationAuditSubscriber::PROVIDER_ATTRIBUTE));

            return;
        }
        $this->fail('Expected ValidationException');
    }

    public function testValidateClaimsWrongState(): void
    {
        $request = new Request(query: ['state' => 'wrong_test_state']);
        $this->setSessionOnRequest($request);

        try {
            $this->authenticator->authenticate($request);
        } catch (ValidationException $thrown) {
            $this->assertSame('Invalid state', $thrown->getMessage());
            $record = $this->logger->singleRecord();
            $this->assertSame(LogLevel::WARNING, $record['level'], 'A stale bookmark or CSRF probe is routine, not an operator problem');
            $this->assertStringContainsString('invalid state', $record['message']);
            $this->assertSame('test_provider_1', $record['context']['provider'] ?? null);

            return;
        }
        $this->fail('Expected ValidationException');
    }

    public function testValidateClaimsEmptyNonce(): void
    {
        $request = new Request(query: ['state' => 'test_state']);
        $this->setSessionOnRequest($request, nonce: null);

        try {
            $this->authenticator->authenticate($request);
        } catch (ValidationException $thrown) {
            $this->assertSame('Nonce empty or not found', $thrown->getMessage());
            $record = $this->logger->singleRecord();
            $this->assertSame(LogLevel::WARNING, $record['level'], 'Same class of cause as an invalid state');
            $this->assertStringContainsString('nonce empty or not found', $record['message']);
            $this->assertSame('test_provider_1', $record['context']['provider'] ?? null);

            return;
        }
        $this->fail('Expected ValidationException');
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

            $record = $this->logger->singleRecord();
            $this->assertSame(LogLevel::ERROR, $record['level'], 'This is the expired-secret path — it must reach the operator');
            $this->assertStringContainsString('validating the authorization code', $record['message']);
            $this->assertSame($cause, $record['context']['exception'] ?? null);
            $this->assertSame('test_provider_1', $record['context']['provider'] ?? null);

            return;
        }
        $this->fail('Expected ValidationException');
    }

    public function testValidateClaimsSuccess(): void
    {
        $stubProvider = $this->createStub(OpenIdConfigurationProvider::class);

        $claims = new \stdClass();
        $claims->email = 'test@example.org';
        $claims->name = 'Test Tester';
        $stubProvider->method('validateIdToken')->willReturn($claims);

        // Expect the exact provider key from the session, so a lookup with a
        // mangled key fails the test instead of silently matching any key.
        $mockProviderManager = $this->createMock(OpenIdConfigurationProviderManager::class);
        $mockProviderManager->expects($this->once())
            ->method('getProvider')
            ->with('test_provider_1')
            ->willReturn($stubProvider);
        $authenticator = new TestAuthenticator($mockProviderManager);
        $authenticator->setLogger($this->logger);

        $request = new Request(query: ['state' => 'test_state', 'code' => 'test_code']);
        $this->setSessionOnRequest($request);

        $passport = $authenticator->authenticate($request);

        $this->assertSame('test@example.org', $passport->getUser()->getUserIdentifier());

        // The claims contract: the IdP claims plus the provider key that
        // authenticated the user.
        $this->assertSame('Test Tester', $authenticator->lastClaims['name'] ?? null);
        $this->assertSame('test_provider_1', $authenticator->lastClaims['open_id_connect_provider'] ?? null);
        $this->assertSame([], $this->logger->records, 'A successful login must not log a failure.');
    }

    /**
     * Consumers who disable autoconfiguration never get `setLogger()` called and
     * fall back to the `NullLogger`. Every failure path must still raise its own
     * exception, so each one is exercised here without a logger.
     */
    public function testEveryFailurePathWorksWithoutALogger(): void
    {
        $cause = new ClaimsException('test message');
        $stubProvider = $this->createStub(OpenIdConfigurationProvider::class);
        $stubProvider->method('validateIdToken')->willThrowException($cause);
        $this->stubProviderManager->method('getProvider')->willReturn($stubProvider);

        $authenticator = new TestAuthenticator($this->stubProviderManager);

        // Invalid state.
        $request = new Request(query: ['state' => 'wrong_test_state']);
        $this->setSessionOnRequest($request);
        $this->assertValidationExceptionMessage($authenticator, $request, 'Invalid state');

        // Empty nonce.
        $request = new Request(query: ['state' => 'test_state']);
        $this->setSessionOnRequest($request, nonce: null);
        $this->assertValidationExceptionMessage($authenticator, $request, 'Nonce empty or not found');

        // Token exchange / claims validation failure.
        $request = new Request(query: ['state' => 'test_state', 'code' => 'test_code']);
        $this->setSessionOnRequest($request);
        $this->assertValidationExceptionMessage($authenticator, $request, 'test message');

        // onAuthenticationFailure().
        try {
            $authenticator->onAuthenticationFailure(new Request(), new AuthenticationException('boom'));
            $this->fail('Expected AuthenticationFailedException');
        } catch (AuthenticationFailedException $thrown) {
            $this->assertStringContainsString('boom', $thrown->getMessage());
        }
    }

    private function assertValidationExceptionMessage(OpenIdLoginAuthenticator $authenticator, Request $request, string $expectedMessage): void
    {
        try {
            $authenticator->authenticate($request);
        } catch (ValidationException $thrown) {
            $this->assertSame($expectedMessage, $thrown->getMessage());

            return;
        }
        $this->fail(sprintf('Expected ValidationException "%s"', $expectedMessage));
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

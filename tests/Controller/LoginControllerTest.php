<?php

namespace ItkDev\OpenIdConnectBundle\Tests\Controller;

use ItkDev\OpenIdConnect\Exception\CacheException;
use ItkDev\OpenIdConnect\Exception\HttpException;
use ItkDev\OpenIdConnect\Exception\JsonException;
use ItkDev\OpenIdConnect\Security\OpenIdConfigurationProvider;
use ItkDev\OpenIdConnectBundle\Controller\LoginController;
use ItkDev\OpenIdConnectBundle\Exception\InvalidProviderException;
use ItkDev\OpenIdConnectBundle\Security\OpenIdConfigurationProviderManager;
use ItkDev\OpenIdConnectBundle\Tests\TestLogger;
use ItkDev\OpenIdConnectBundle\Util\ClientSecretExpiryChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class LoginControllerTest extends TestCase
{
    private const string NOW = '2026-08-19 09:00:00';

    private TestLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new TestLogger();
    }

    /**
     * @param array<string, string|null> $expiryDates
     */
    private function createExpiryChecker(array $expiryDates = ['test' => null], int $warningDays = 30): ClientSecretExpiryChecker
    {
        return new ClientSecretExpiryChecker(
            new MockClock(new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC'))),
            $expiryDates,
            $warningDays,
            new TestLogger(),
        );
    }

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
            ->willReturn('https://provider.example.org/authorize');

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
        $this->assertSame('https://provider.example.org/authorize', $response->getTargetUrl());
        $this->assertSame([], $this->logger->records, 'A successful login must not log a failure.');
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

        $controller = new LoginController($mockProviderManager, $this->logger, $this->createExpiryChecker());

        try {
            $controller->login(new Request(), $this->createStub(SessionInterface::class), 'bogus');
        } catch (NotFoundHttpException $thrown) {
            $this->assertSame(404, $thrown->getStatusCode());
            $this->assertStringContainsString('bogus', $thrown->getMessage());
            $this->assertSame($cause, $thrown->getPrevious(), 'Original exception must be chained');

            $record = $this->logger->singleRecord();
            $this->assertSame(LogLevel::WARNING, $record['level'], 'A client hitting an unknown URL is not an operator problem');
            $this->assertStringContainsString('unknown provider', $record['message']);
            $this->assertSame('bogus', $record['context']['provider'] ?? null);
            $this->assertSame($cause, $record['context']['exception'] ?? null);

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

            $record = $this->logger->singleRecord();
            $this->assertSame(LogLevel::ERROR, $record['level'], 'IdP down or cache broken — operator action');
            $this->assertStringContainsString('cannot reach provider', $record['message']);
            $this->assertSame('test', $record['context']['provider'] ?? null);
            $this->assertSame($cause, $record['context']['exception'] ?? null);

            return;
        }
        $this->fail('Expected ServiceUnavailableHttpException');
    }

    public function testExpiredSecretLogsCriticalButStillAttemptsTheLogin(): void
    {
        $stubProvider = $this->createStub(OpenIdConfigurationProvider::class);
        $stubProvider->method('generateNonce')->willReturn('1234');
        $stubProvider->method('generateState')->willReturn('abcd');
        $stubProvider->method('getAuthorizationUrl')->willReturn('https://provider.example.org/authorize');

        $controller = $this->createController($stubProvider, $this->createExpiryChecker(['test' => '2026-07-01']));

        $response = $controller->login(new Request(), $this->createStub(SessionInterface::class), 'test');

        // Fail open: the date is maintained by hand and can be out of step with
        // the secret, so a stale one must not block logins that work. The identity
        // provider decides; this only records why.
        $this->assertSame('https://provider.example.org/authorize', $response->getTargetUrl());

        $record = $this->logger->singleRecord();
        $this->assertSame(LogLevel::CRITICAL, $record['level'], 'Every login through this provider is expected to fail from here');
        $this->assertStringContainsString('past its configured expiry', $record['message']);
        // The message carries the remedy for a stale date too, since the record is
        // now the only place an operator learns about either case.
        $this->assertStringContainsString('update client_secret_expires_at', $record['message']);
        $this->assertSame('test', $record['context']['provider']);
        $this->assertSame('expired', $record['context']['status']);
        // 49 days and 9 hours past, and floor() rounds a negative away from zero.
        $this->assertSame(-50, $record['context']['days_remaining']);
    }

    public function testExpiringSoonWarnsButStillLogsIn(): void
    {
        $stubProvider = $this->createStub(OpenIdConfigurationProvider::class);
        $stubProvider->method('generateNonce')->willReturn('1234');
        $stubProvider->method('generateState')->willReturn('abcd');
        $stubProvider->method('getAuthorizationUrl')->willReturn('https://provider.example.org/authorize');

        $controller = $this->createController($stubProvider, $this->createExpiryChecker(['test' => '2026-09-01']));

        $response = $controller->login(new Request(), $this->createStub(SessionInterface::class), 'test');

        // The warning window is precisely when logins must keep working.
        $this->assertSame('https://provider.example.org/authorize', $response->getTargetUrl());

        $record = $this->logger->singleRecord();
        $this->assertSame(LogLevel::WARNING, $record['level']);
        $this->assertStringContainsString('expires soon', $record['message']);
        $this->assertSame('expiring_soon', $record['context']['status']);
        $this->assertSame(12, $record['context']['days_remaining']);
        $this->assertSame('2026-09-01T00:00:00+00:00', $record['context']['expires_at']);
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function quietExpiryProvider(): iterable
    {
        yield 'comfortably in the future' => ['2027-01-31'];
        // Unknown is not a per-request problem: the extension already emits a
        // deprecation at compile time, and warning on every login would be noise.
        yield 'no date configured' => [null];
    }

    #[DataProvider('quietExpiryProvider')]
    public function testHealthySecretLogsNothing(?string $expiresAt): void
    {
        $stubProvider = $this->createStub(OpenIdConfigurationProvider::class);
        $stubProvider->method('generateNonce')->willReturn('1234');
        $stubProvider->method('generateState')->willReturn('abcd');
        $stubProvider->method('getAuthorizationUrl')->willReturn('https://provider.example.org/authorize');

        $controller = $this->createController($stubProvider, $this->createExpiryChecker(['test' => $expiresAt]));

        $controller->login(new Request(), $this->createStub(SessionInterface::class), 'test');

        $this->assertSame([], $this->logger->records);
    }

    public function testUnknownProviderIsRefusedBeforeTheExpiryCheck(): void
    {
        // The expiry check runs after the provider resolves, so an unknown key is
        // still a 404 and does not get an expiry record attached to it.
        $stubProviderManager = $this->createStub(OpenIdConfigurationProviderManager::class);
        $stubProviderManager->method('getProvider')->willThrowException(new InvalidProviderException('Invalid provider: bogus'));

        $controller = new LoginController($stubProviderManager, $this->logger, $this->createExpiryChecker(['bogus' => '2026-07-01']));

        try {
            $controller->login(new Request(), $this->createStub(SessionInterface::class), 'bogus');
        } catch (NotFoundHttpException) {
            $record = $this->logger->singleRecord();
            $this->assertStringContainsString('unknown provider', $record['message'], 'Only the 404 is reported, not the expiry');

            return;
        }
        $this->fail('Expected NotFoundHttpException');
    }

    private function createController(OpenIdConfigurationProvider $provider, ?ClientSecretExpiryChecker $expiryChecker = null): LoginController
    {
        $mockProviderManager = $this->createMock(OpenIdConfigurationProviderManager::class);
        $mockProviderManager
            ->expects($this->once())
            ->method('getProvider')
            ->with('test')
            ->willReturn($provider);

        return new LoginController($mockProviderManager, $this->logger, $expiryChecker ?? $this->createExpiryChecker());
    }
}

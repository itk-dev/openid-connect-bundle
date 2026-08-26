<?php

namespace ItkDev\OpenIdConnectBundle\Tests\Controller;

use ItkDev\OpenIdConnect\Exception\CacheException;
use ItkDev\OpenIdConnect\Exception\HttpException;
use ItkDev\OpenIdConnect\Exception\JsonException;
use ItkDev\OpenIdConnect\Security\OpenIdConfigurationProvider;
use ItkDev\OpenIdConnectBundle\Controller\LoginController;
use ItkDev\OpenIdConnectBundle\Exception\InvalidProviderException;
use ItkDev\OpenIdConnectBundle\Security\OpenIdConfigurationProviderManager;
use ItkDev\OpenIdConnectBundle\Security\OpenIdLoginAuthenticator;
use ItkDev\OpenIdConnectBundle\Tests\TestLogger;
use ItkDev\OpenIdConnectBundle\Util\ClientSecretExpiryChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
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
        $matcher = $this->exactly(4);
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
                if (4 === $matcher->numberOfInvocations()) {
                    // Written even with PKCE off, so a verifier left by an earlier
                    // login cannot be redeemed against this one's code.
                    $this->assertEquals('oauth2pkce_verifier', $parameters[0]);
                    $this->assertNull($parameters[1]);
                }
            });

        $response = $controller->login($request, $mockSession, 'test');
        $this->assertSame('https://provider.example.org/authorize', $response->getTargetUrl());
        $this->assertSame([], $this->logger->records, 'A successful login must not log a failure.');
    }

    public function testPkceSendsAChallengeAndKeepsTheVerifier(): void
    {
        $mockProvider = $this->createMock(OpenIdConfigurationProvider::class);
        $mockProvider->method('generateNonce')->willReturn('1234');
        $mockProvider->method('generateState')->willReturn('abcd');
        $mockProvider
            ->expects($this->once())
            ->method('generatePkceVerifier')
            ->willReturn('the-verifier');
        $mockProvider
            ->expects($this->once())
            ->method('getPkceChallenge')
            ->with('the-verifier')
            ->willReturn('the-challenge');
        $mockProvider
            ->expects($this->once())
            ->method('getAuthorizationUrl')
            ->with([
                'state' => 'abcd',
                'nonce' => '1234',
                'response_type' => 'code',
                'scope' => 'openid email profile',
                // The library adds code_challenge_method=S256 alongside this.
                'code_challenge' => 'the-challenge',
            ])
            ->willReturn('https://provider.example.org/authorize');

        $controller = $this->createController($mockProvider, pkce: true);
        $session = new Session(new MockArraySessionStorage());

        $controller->login(new Request(), $session, 'test');

        // The verifier is kept, never sent. Only the challenge goes over the wire.
        $this->assertSame('the-verifier', $session->get('oauth2pkce_verifier'));
    }

    public function testPkceCanBeTurnedOffForAProviderThatRejectsIt(): void
    {
        $mockProvider = $this->createMock(OpenIdConfigurationProvider::class);
        $mockProvider->method('generateNonce')->willReturn('1234');
        $mockProvider->method('generateState')->willReturn('abcd');
        $mockProvider->expects($this->never())->method('generatePkceVerifier');
        $mockProvider->expects($this->never())->method('getPkceChallenge');
        $mockProvider
            ->expects($this->once())
            ->method('getAuthorizationUrl')
            ->with($this->logicalNot($this->arrayHasKey('code_challenge')))
            ->willReturn('https://provider.example.org/authorize');

        $controller = $this->createController($mockProvider, pkce: false);
        $session = new Session(new MockArraySessionStorage());

        $controller->login(new Request(), $session, 'test');

        $this->assertNull($session->get('oauth2pkce_verifier'));
    }

    /**
     * A verifier from an abandoned login must not be redeemable against the code this
     * one is about to receive, so the key is written on every login rather than only
     * when PKCE is on.
     */
    public function testAStaleVerifierIsOverwrittenWhenPkceIsOff(): void
    {
        $stubProvider = $this->createStub(OpenIdConfigurationProvider::class);
        $stubProvider->method('generateNonce')->willReturn('1234');
        $stubProvider->method('generateState')->willReturn('abcd');
        $stubProvider->method('getAuthorizationUrl')->willReturn('https://provider.example.org/authorize');

        $session = new Session(new MockArraySessionStorage());
        $session->set('oauth2pkce_verifier', 'left-over-from-an-earlier-login');

        $this->createController($stubProvider, pkce: false)->login(new Request(), $session, 'test');

        $this->assertNull($session->get('oauth2pkce_verifier'));
    }

    public function testConfiguredScopesReachTheAuthorizationRequest(): void
    {
        $mockProvider = $this->createMock(OpenIdConfigurationProvider::class);
        $mockProvider->method('generateNonce')->willReturn('1234');
        $mockProvider->method('generateState')->willReturn('abcd');
        $mockProvider
            ->expects($this->once())
            ->method('getAuthorizationUrl')
            ->with($this->callback(
                static fn (array $options): bool => 'openid profile groups' === ($options['scope'] ?? null)
            ))
            ->willReturn('https://provider.example.org/authorize');

        $controller = $this->createController($mockProvider, scopes: ['openid', 'profile', 'groups']);

        $controller->login(new Request(), new Session(new MockArraySessionStorage()), 'test');
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

    /**
     * @param string[] $scopes
     */
    private function createController(OpenIdConfigurationProvider $provider, ?ClientSecretExpiryChecker $expiryChecker = null, bool $pkce = false, array $scopes = ['openid', 'email', 'profile']): LoginController
    {
        $mockProviderManager = $this->createMock(OpenIdConfigurationProviderManager::class);
        $mockProviderManager
            ->expects($this->once())
            ->method('getProvider')
            ->with('test')
            ->willReturn($provider);
        $mockProviderManager
            ->method('isPkceEnabled')
            ->with('test')
            ->willReturn($pkce);
        $mockProviderManager
            ->method('getScopes')
            ->with('test')
            ->willReturn($scopes);

        return new LoginController($mockProviderManager, $this->logger, $expiryChecker ?? $this->createExpiryChecker());
    }

    private function loginWith(?string $target): Session
    {
        // A stub, not a mock: nothing here asserts on the provider itself.
        $provider = $this->createStub(OpenIdConfigurationProvider::class);
        $provider->method('generateNonce')->willReturn('1234');
        $provider->method('generateState')->willReturn('abcd');
        $provider->method('getAuthorizationUrl')->willReturn('https://provider.example.org/authorize');

        $query = ['provider' => 'test'];

        if (null !== $target) {
            $query[LoginController::TARGET_PATH_PARAMETER] = $target;
        }

        $session = new Session(new MockArraySessionStorage());
        $this->createController($provider)->login(new Request(query: $query), $session, 'test');

        return $session;
    }

    public function testATargetPathOnTheLinkIsRemembered(): void
    {
        $session = $this->loginWith('/admin/reports?page=2');

        $this->assertSame('/admin/reports?page=2', $session->get(OpenIdLoginAuthenticator::TARGET_PATH_SESSION_KEY));
        $this->assertSame([], $this->logger->records);
    }

    public function testWithoutATargetPathNothingIsRememberedOrLogged(): void
    {
        $session = $this->loginWith(null);

        $this->assertFalse($session->has(OpenIdLoginAuthenticator::TARGET_PATH_SESSION_KEY));
        $this->assertSame([], $this->logger->records);
    }

    /**
     * The last login link wins. A target left behind by an abandoned link would
     * otherwise be spent by whatever login came next, sending the user somewhere they
     * did not ask for this time.
     */
    public function testAPlainLoginLinkForgetsAnEarlierTarget(): void
    {
        $provider = $this->createStub(OpenIdConfigurationProvider::class);
        $provider->method('generateNonce')->willReturn('1234');
        $provider->method('generateState')->willReturn('abcd');
        $provider->method('getAuthorizationUrl')->willReturn('https://provider.example.org/authorize');

        $session = new Session(new MockArraySessionStorage());
        $session->set(OpenIdLoginAuthenticator::TARGET_PATH_SESSION_KEY, '/admin/abandoned');

        $this->createController($provider)->login(new Request(query: ['provider' => 'test']), $session, 'test');

        $this->assertFalse($session->has(OpenIdLoginAuthenticator::TARGET_PATH_SESSION_KEY));
    }

    /**
     * This value reaches a `Location` header after a successful login, so anything
     * that is not plainly a path inside this application would make the login route
     * an open redirect for anyone who can get a user to follow a link.
     *
     * @return iterable<string, array{string}>
     */
    public static function unusableTargetPathProvider(): iterable
    {
        yield 'absolute url' => ['https://evil.example.org/phish'];
        yield 'scheme relative' => ['//evil.example.org/phish'];
        yield 'backslash scheme relative' => ['/\evil.example.org/phish'];
        yield 'backslash anywhere' => ['/admin\reports'];
        yield 'a scheme further in' => ['/redirect?to=https://evil.example.org'];
        yield 'no leading slash' => ['admin/reports'];
        yield 'empty' => [''];
        yield 'a bare word' => ['dashboard'];
        yield 'header split attempt' => ["/admin\r\nSet-Cookie: session=stolen"];
        yield 'null byte' => ["/admin\0/reports"];
        yield 'javascript' => ['javascript:alert(1)'];
    }

    #[DataProvider('unusableTargetPathProvider')]
    public function testAnUnusableTargetPathIsDroppedAndReported(string $target): void
    {
        $session = $this->loginWith($target);

        $this->assertFalse(
            $session->has(OpenIdLoginAuthenticator::TARGET_PATH_SESSION_KEY),
            'A value that is not a local path must never reach a Location header'
        );

        $record = $this->logger->singleRecord();
        $this->assertSame(LogLevel::WARNING, $record['level']);
        $this->assertStringContainsString('ignoring an unusable target_path', $record['message']);
        $this->assertSame($target, $record['context']['target_path']);
    }
}

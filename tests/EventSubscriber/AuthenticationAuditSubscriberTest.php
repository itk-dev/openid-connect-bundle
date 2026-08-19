<?php

namespace ItkDev\OpenIdConnectBundle\Tests\EventSubscriber;

use ItkDev\OpenIdConnectBundle\EventSubscriber\AuthenticationAuditSubscriber;
use ItkDev\OpenIdConnectBundle\Log\AuthenticationAuditLogger;
use ItkDev\OpenIdConnectBundle\Security\CliLoginTokenAuthenticator;
use ItkDev\OpenIdConnectBundle\Security\OpenIdConfigurationProviderManager;
use ItkDev\OpenIdConnectBundle\Security\OpenIdLoginAuthenticator;
use ItkDev\OpenIdConnectBundle\Tests\Security\TestAuthenticator;
use ItkDev\OpenIdConnectBundle\Tests\TestLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\FormLoginAuthenticator;
use Symfony\Component\Security\Http\Authenticator\JsonLoginAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

class AuthenticationAuditSubscriberTest extends TestCase
{
    private TestLogger $logger;
    private AuthenticationAuditSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->logger = new TestLogger();
        $this->subscriber = new AuthenticationAuditSubscriber(
            new AuthenticationAuditLogger($this->logger, enabled: true)
        );
    }

    public function testADisabledTrailReadsNothingFromTheEvent(): void
    {
        // Reachable whenever `enabled` comes from an environment variable: the
        // extension cannot remove the subscriber then, so this guard is what keeps
        // the "no personal data assembled" promise.
        $subscriber = new AuthenticationAuditSubscriber(
            new AuthenticationAuditLogger($this->logger, enabled: false)
        );

        $token = $this->createMock(TokenInterface::class);
        $token->expects($this->never())->method('getUserIdentifier');

        $subscriber->onLoginSuccess(new LoginSuccessEvent(
            $this->oidcAuthenticator(),
            $this->createStub(Passport::class),
            $token,
            new Request(),
            null,
            'main',
        ));

        $this->assertSame([], $this->logger->records);
    }

    public function testADisabledTrailIgnoresFailuresToo(): void
    {
        $subscriber = new AuthenticationAuditSubscriber(
            new AuthenticationAuditLogger($this->logger, enabled: false)
        );

        // Nothing may be read off the request either — the client IP is personal
        // data, and reading it is already doing what the guard exists to prevent.
        // (The exception cannot serve as the sentinel: getPrevious() is final.)
        $request = $this->createMock(Request::class);
        $request->expects($this->never())->method('getClientIp');

        $subscriber->onLoginFailure(new LoginFailureEvent(
            new AuthenticationException('Invalid state'),
            $this->oidcAuthenticator(),
            $request,
            null,
            'main',
        ));

        $this->assertSame([], $this->logger->records);
    }

    public function testSubscribesToBothSecurityEvents(): void
    {
        $this->assertSame([
            LoginSuccessEvent::class => 'onLoginSuccess',
            LoginFailureEvent::class => 'onLoginFailure',
        ], AuthenticationAuditSubscriber::getSubscribedEvents());
    }

    public function testOidcLoginSuccessIsRecordedWithTheProvider(): void
    {
        $request = new Request();
        // Set by validateClaims(), because the provider key reaches no event.
        $request->attributes->set(AuthenticationAuditSubscriber::PROVIDER_ATTRIBUTE, 'azure');
        $request->server->set('REMOTE_ADDR', '203.0.113.4');

        $this->subscriber->onLoginSuccess($this->successEvent($this->oidcAuthenticator(), $request, 'user@example.org'));

        $record = $this->logger->singleRecord();
        $this->assertSame(AuthenticationAuditLogger::EVENT_LOGIN_SUCCEEDED, $record['message']);
        $this->assertSame('oidc', $record['context']['method']);
        $this->assertSame(TestAuthenticator::class, $record['context']['authenticator'], 'Consumers subclass the base authenticator, so the concrete class is what says which one ran');
        $this->assertSame('user@example.org', $record['context']['subject']);
        $this->assertSame('azure', $record['context']['provider']);
        $this->assertSame('main', $record['context']['firewall']);
        $this->assertSame('203.0.113.4', $record['context']['ip']);
    }

    public function testCliTokenLoginIsRecordedAsItsOwnMethod(): void
    {
        // Consuming a CLI login token is a successful login by a different means;
        // the authenticator is what tells the two apart, so no separate call site
        // is needed for "token consumed".
        $authenticator = $this->createStub(CliLoginTokenAuthenticator::class);

        $this->subscriber->onLoginSuccess($this->successEvent($authenticator, new Request(), 'operator@example.org'));

        $record = $this->logger->singleRecord();
        $this->assertSame('cli_token', $record['context']['method']);
        $authenticator = $record['context']['authenticator'];
        $this->assertIsString($authenticator);
        $this->assertStringContainsString('CliLoginTokenAuthenticator', $authenticator);
        $this->assertNull($record['context']['provider'], 'A CLI token login has no OIDC provider');
    }

    /**
     * Authenticators from elsewhere in the application.
     *
     * These events fire for every authenticator, not just this bundle's. Projects
     * using the bundle commonly offer password login alongside OIDC, and those
     * logins must stay out of this trail.
     *
     * @return iterable<string, array{class-string<AuthenticatorInterface>}>
     */
    public static function foreignAuthenticatorProvider(): iterable
    {
        yield 'form login (password)' => [FormLoginAuthenticator::class];
        yield 'json login' => [JsonLoginAuthenticator::class];
        yield 'any other authenticator' => [AuthenticatorInterface::class];
    }

    /**
     * @param class-string<AuthenticatorInterface> $authenticatorClass
     */
    #[DataProvider('foreignAuthenticatorProvider')]
    public function testLoginSuccessFromAnotherAuthenticatorIsIgnored(string $authenticatorClass): void
    {
        $authenticator = $this->createStub($authenticatorClass);

        $this->subscriber->onLoginSuccess($this->successEvent($authenticator, new Request(), 'user@example.org'));

        $this->assertSame([], $this->logger->records);
    }

    /**
     * @param class-string<AuthenticatorInterface> $authenticatorClass
     */
    #[DataProvider('foreignAuthenticatorProvider')]
    public function testLoginFailureFromAnotherAuthenticatorIsIgnoredToo(string $authenticatorClass): void
    {
        $event = new LoginFailureEvent(
            new AuthenticationException('not ours'),
            $this->createStub($authenticatorClass),
            new Request(),
            null,
            'main',
        );

        $this->subscriber->onLoginFailure($event);

        $this->assertSame([], $this->logger->records);
    }

    public function testLoginFailureIsRecordedWithoutASubject(): void
    {
        $request = new Request();
        $request->attributes->set(AuthenticationAuditSubscriber::PROVIDER_ATTRIBUTE, 'azure');

        $event = new LoginFailureEvent(
            new AuthenticationException('Invalid state'),
            $this->oidcAuthenticator(),
            $request,
            null,
            'main',
        );

        $this->subscriber->onLoginFailure($event);

        $record = $this->logger->singleRecord();
        $this->assertSame(AuthenticationAuditLogger::EVENT_LOGIN_FAILED, $record['message']);
        $this->assertSame('failure', $record['context']['outcome']);
        $this->assertSame('Invalid state', $record['context']['reason']);
        $this->assertSame('azure', $record['context']['provider']);
        // The passport is assigned only after authenticate() returns, and this
        // bundle throws inside it, so there is genuinely no identity here.
        $this->assertNull($record['context']['subject']);
    }

    public function testLoginFailureRecordsTheChainedCauseRatherThanTheSanitisedOne(): void
    {
        // AuthenticatorManager swaps sensitive causes for a generic
        // BadCredentialsException. A trail saying only "Bad credentials." records
        // that something failed without recording what.
        $event = new LoginFailureEvent(
            new BadCredentialsException('Bad credentials.', 0, new AuthenticationException('invalid_client: secret expired')),
            $this->oidcAuthenticator(),
            new Request(),
            null,
            'main',
        );

        $this->subscriber->onLoginFailure($event);

        $this->assertSame('invalid_client: secret expired', $this->logger->singleRecord()['context']['reason']);
    }

    public function testLoginFailureUsesThePassportSubjectWhenOneExists(): void
    {
        // Rare for this bundle, but a subclass can fail after building a passport.
        $user = $this->createStub(UserInterface::class);
        $user->method('getUserIdentifier')->willReturn('late@example.org');
        $passport = new SelfValidatingPassport(new UserBadge('late@example.org', fn () => $user));

        $event = new LoginFailureEvent(
            new AuthenticationException('User provisioning failed'),
            $this->oidcAuthenticator(),
            new Request(),
            null,
            'main',
            $passport,
        );

        $this->subscriber->onLoginFailure($event);

        $this->assertSame('late@example.org', $this->logger->singleRecord()['context']['subject']);
    }

    public function testMissingProviderAttributeIsRecordedAsNull(): void
    {
        $this->subscriber->onLoginSuccess($this->successEvent($this->oidcAuthenticator(), new Request(), 'user@example.org'));

        $this->assertNull($this->logger->singleRecord()['context']['provider']);
    }

    private function oidcAuthenticator(): OpenIdLoginAuthenticator
    {
        return new TestAuthenticator($this->createStub(OpenIdConfigurationProviderManager::class));
    }

    private function successEvent(AuthenticatorInterface $authenticator, Request $request, string $userIdentifier): LoginSuccessEvent
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUserIdentifier')->willReturn($userIdentifier);

        return new LoginSuccessEvent(
            $authenticator,
            $this->createStub(Passport::class),
            $token,
            $request,
            null,
            'main',
        );
    }
}

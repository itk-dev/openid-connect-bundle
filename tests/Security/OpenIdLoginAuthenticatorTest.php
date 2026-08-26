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
use ItkDev\OpenIdConnectBundle\Exception\ProviderErrorException;
use ItkDev\OpenIdConnectBundle\Security\OpenIdConfigurationProviderManager;
use ItkDev\OpenIdConnectBundle\Security\OpenIdLoginAuthenticator;
use ItkDev\OpenIdConnectBundle\Tests\TestLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\RouterInterface;
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

    /**
     * A real manager, not a stub: the path comparison lives there, and stubbing it
     * would mean reimplementing normalization in the test — where a bug in the real
     * one could not be seen.
     *
     * @param array<string, string> $paths callback_path per provider
     */
    private function managerWithPaths(array $paths): OpenIdConfigurationProviderManager
    {
        $providers = [];

        foreach ($paths as $key => $path) {
            $providers[$key] = [
                'metadata_url' => 'https://provider.example.org/.well-known/openid-configuration',
                'client_id' => 'id',
                'client_secret' => 'secret',
                'callback_path' => $path,
            ];
        }

        $config = ['default_providers_options' => [], 'providers' => $providers];

        return new OpenIdConfigurationProviderManager($this->createStub(RouterInterface::class), $config);
    }

    /**
     * @param array<string, string> $paths
     */
    private function authenticatorWithPaths(array $paths): TestAuthenticator
    {
        $authenticator = new TestAuthenticator($this->managerWithPaths($paths));
        $authenticator->setLogger($this->logger);

        return $authenticator;
    }

    /**
     * `state` and `code` are necessary but no longer sufficient: without the path
     * check any URL under the firewall is a callback, so an unauthenticated caller
     * can turn any page into a failed login — a 500, since the bundle fails closed.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function callbackPathProvider(): iterable
    {
        yield 'the configured path' => ['/callback_uri', true];
        yield 'trailing slash is the same path' => ['/callback_uri/', true];
        yield 'another provider on this authenticator' => ['/other_callback', true];
        yield 'a protected page' => ['/protected', false];
        yield 'the root' => ['/', false];
        yield 'below the callback path' => ['/callback_uri/extra', false];
        yield 'above the callback path' => ['/callback', false];
        yield 'differing in case' => ['/Callback_Uri', false];
        yield 'the path as a query parameter' => ['/protected/callback_uri', false];
    }

    #[DataProvider('callbackPathProvider')]
    public function testSupportsOnlyTheConfiguredCallbackPaths(string $path, bool $expected): void
    {
        $authenticator = $this->authenticatorWithPaths([
            'test_provider_1' => '/callback_uri',
            'test_provider_2' => '/other_callback',
        ]);

        $request = Request::create($path.'?state=abcd&code=xyz');

        $this->assertSame($expected, $authenticator->supports($request));
    }

    /**
     * Deployments where the request path is not the whole story.
     *
     * `getPathInfo()` has both a subdirectory's base path and a trusted
     * `X-Forwarded-Prefix` stripped out of it, while a configured `redirect_uri`
     * contains them — it is the URL the identity provider was given. Comparing path
     * info alone would reject every callback in either deployment.
     *
     * @return iterable<string, array{string, string, bool}>
     */
    public static function baseUrlProvider(): iterable
    {
        //                                   configured path,          base url, request path info, expected
        yield 'subdirectory deployment' => ['/app/callback_uri', '/app', true];
        yield 'trusted proxy prefix' => ['/prefix/callback_uri', '/prefix', true];
        yield 'root deployment' => ['/callback_uri', '', true];
        // A proxy that rewrites without a prefix header: the internal path really is
        // different, which is what callback_path exists to declare.
        yield 'rewriting proxy, no header' => ['/prefix/callback_uri', '', false];
    }

    #[DataProvider('baseUrlProvider')]
    public function testTheCallbackPathIncludesTheBaseUrl(string $configured, string $baseUrl, bool $expected): void
    {
        $authenticator = $this->authenticatorWithPaths(['test_provider_1' => $configured]);

        $request = new RequestWithBaseUrl($baseUrl, ['state' => 'abcd', 'code' => 'xyz']);
        $request->server->set('REQUEST_URI', $baseUrl.'/callback_uri?state=abcd&code=xyz');

        $this->assertSame($expected, $authenticator->supports($request));
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function incompleteCallbackProvider(): iterable
    {
        yield 'neither' => [[]];
        yield 'state only' => [['state' => 'abcd']];
        yield 'code only' => [['code' => 'xyz']];
        yield 'error only' => [['error' => 'access_denied']];
        yield 'error and code, no state' => [['error' => 'access_denied', 'code' => 'xyz']];
    }

    #[DataProvider('incompleteCallbackProvider')]
    public function testTheRightPathAloneIsNotACallback(array $query): void
    {
        $authenticator = $this->authenticatorWithPaths(['test_provider_1' => '/callback_uri']);

        $this->assertFalse($authenticator->supports(Request::create('/callback_uri?'.http_build_query($query))));
    }

    /**
     * A refusal is a callback too, and the path is still what decides. RFC 6749
     * §4.1.2.1 sends `error` with `state` and no `code`.
     */
    #[DataProvider('callbackPathProvider')]
    public function testAnErrorCallbackIsAlsoACallback(string $path, bool $expected): void
    {
        $authenticator = $this->authenticatorWithPaths([
            'test_provider_1' => '/callback_uri',
            'test_provider_2' => '/other_callback',
        ]);

        $this->assertSame($expected, $authenticator->supports(Request::create($path.'?state=abcd&error=access_denied')));
    }

    /**
     * The one that keeps the loop closed. A provider is entitled to send an empty
     * `error`, and if that is not recognised as a callback the firewall answers it,
     * the entry point mints a fresh state, and the next refusal comes back with a
     * state that matches — a loop indistinguishable from a first attempt.
     */
    public function testAnEmptyErrorIsStillACallback(): void
    {
        $authenticator = $this->authenticatorWithPaths(['test_provider_1' => '/callback_uri']);

        $this->assertTrue($authenticator->supports(Request::create('/callback_uri?state=abcd&error=')));
    }

    /**
     * A subclass bound to one provider does not answer another provider's callback,
     * which is what lets one authenticator per provider share a firewall.
     */
    public function testASubclassCanNarrowTheProvidersItAnswersFor(): void
    {
        $authenticator = new SingleProviderAuthenticator($this->managerWithPaths([
            'test_provider_1' => '/callback_uri',
            'test_provider_2' => '/other_callback',
        ]));

        $this->assertTrue($authenticator->supports(Request::create('/callback_uri?state=a&code=b')));
        $this->assertFalse($authenticator->supports(Request::create('/other_callback?state=a&code=b')));
    }

    /**
     * A provider with no derivable path contributes no match rather than matching
     * everything, which would be the bug this constraint removes.
     */
    public function testAProviderWithoutAPathMatchesNothing(): void
    {
        // No redirect_uri, redirect_route or callback_path: nothing to match on, and
        // matching everything is the defect this constraint removes.
        $config = ['default_providers_options' => [], 'providers' => ['test_provider_1' => [
            'metadata_url' => 'https://provider.example.org/.well-known/openid-configuration',
            'client_id' => 'id',
            'client_secret' => 'secret',
        ]]];

        $manager = new OpenIdConfigurationProviderManager($this->createStub(RouterInterface::class), $config);

        $authenticator = new TestAuthenticator($manager);

        $this->assertFalse($authenticator->supports(Request::create('/callback_uri?state=a&code=b')));
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
        // A library exception is not safe merely by being one: skipping only the
        // leading security exceptions would keep this outer cause and leave an
        // AuthenticationException reachable one level further down.
        yield 'library cause hiding a security exception is skipped too' => [
            new ValidationException('outer', 0, new AuthenticationException('inner', 0, $root)),
            $root,
        ];
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

    public function testAProviderErrorIsReportedWithItsCode(): void
    {
        $request = new Request(query: [
            'state' => 'test_state',
            'error' => 'access_denied',
            'error_description' => 'User cancelled',
        ]);
        $this->setSessionOnRequest($request);

        try {
            $this->authenticator->authenticate($request);
        } catch (\Throwable $thrown) {
            // ADR 002: nothing the security component will catch and answer with
            // another trip to the identity provider. Asserted before the type is
            // narrowed, or it holds statically and proves nothing.
            $this->assertNotInstanceOf(AuthenticationException::class, $thrown);
            $this->assertNull($thrown->getPrevious(), 'Nothing beneath it for the ExceptionListener to find');

            $this->assertInstanceOf(ProviderErrorException::class, $thrown);
            $this->assertSame('access_denied', $thrown->getError());
            $this->assertSame('User cancelled', $thrown->getErrorDescription());

            $record = $this->logger->singleRecord();
            $this->assertSame(LogLevel::WARNING, $record['level'], 'A user who changed their mind is not an operator problem');
            $this->assertStringContainsString('refused the request', $record['message']);
            $this->assertSame('test_provider_1', $record['context']['provider'] ?? null);
            $this->assertSame('access_denied', $record['context']['error'] ?? null);
            $this->assertSame('User cancelled', $record['context']['error_description'] ?? null);

            return;
        }
        $this->fail('Expected ProviderErrorException');
    }

    public function testAProviderErrorWithNoDescriptionReportsNull(): void
    {
        $request = new Request(query: ['state' => 'test_state', 'error' => 'access_denied']);
        $this->setSessionOnRequest($request);

        try {
            $this->authenticator->authenticate($request);
        } catch (ProviderErrorException $thrown) {
            $this->assertNull($thrown->getErrorDescription());
            $this->assertArrayHasKey('error_description', $this->logger->singleRecord()['context']);
            $this->assertNull($this->logger->singleRecord()['context']['error_description']);

            return;
        }
        $this->fail('Expected ProviderErrorException');
    }

    /**
     * Constructing a provider pulls in discovery, an HTTP client and a cache pool.
     * A refusal has no use for any of it.
     */
    public function testAProviderErrorNeverBuildsAProvider(): void
    {
        $mockManager = $this->createMock(OpenIdConfigurationProviderManager::class);
        $mockManager->expects($this->never())->method('getProvider');

        $authenticator = new TestAuthenticator($mockManager);
        $authenticator->setLogger($this->logger);

        $request = new Request(query: ['state' => 'test_state', 'error' => 'access_denied']);
        $this->setSessionOnRequest($request);

        $this->expectException(ProviderErrorException::class);
        $authenticator->authenticate($request);
    }

    /**
     * `error` and `error_description` are chosen by whoever built the callback URL.
     * Until the state matches, nothing in it is known to belong to a login this
     * browser started, so none of it is read, logged or repeated back.
     */
    public function testAForgedStateHidesTheProvidersErrorText(): void
    {
        $request = new Request(query: [
            'state' => 'wrong_test_state',
            'error' => 'access_denied',
            'error_description' => "attacker\ntext",
        ]);
        $this->setSessionOnRequest($request);

        try {
            $this->authenticator->authenticate($request);
        } catch (\Throwable $thrown) {
            $this->assertNotInstanceOf(ProviderErrorException::class, $thrown);
            $this->assertInstanceOf(ValidationException::class, $thrown);
            $this->assertSame('Invalid state', $thrown->getMessage());

            $record = $this->logger->singleRecord();
            $this->assertSame(LogLevel::WARNING, $record['level']);
            $this->assertStringContainsString('invalid state', $record['message']);

            $logged = json_encode($this->logger->records);
            $this->assertIsString($logged);
            $this->assertStringNotContainsString('access_denied', $logged, 'Nothing the sender wrote reaches the log');
            $this->assertStringNotContainsString('attacker', $logged);

            return;
        }
        $this->fail('Expected ValidationException');
    }

    /**
     * @return iterable<string, array{string, array<string, string>}>
     */
    public static function oneTimeConsumptionProvider(): iterable
    {
        yield 'provider error' => ['test_state', ['error' => 'access_denied']];
        yield 'invalid state' => ['wrong_test_state', []];
        yield 'missing code' => ['test_state', []];
    }

    /**
     * A value left in the session is one a later request can replay, so a callback
     * is spent whatever becomes of it.
     *
     * @param array<string, string> $extraQuery
     */
    #[DataProvider('oneTimeConsumptionProvider')]
    public function testEveryOneTimeSessionValueIsConsumed(string $state, array $extraQuery): void
    {
        $request = new Request(query: ['state' => $state] + $extraQuery);
        $session = $this->realSessionOnRequest($request);

        try {
            $this->authenticator->authenticate($request);
        } catch (\Throwable) {
            // The failure itself is asserted elsewhere; what matters here is what
            // the session no longer holds.
        }

        $this->assertFalse($session->has('oauth2provider'));
        $this->assertFalse($session->has('oauth2state'));
        $this->assertFalse($session->has('oauth2nonce'));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unusableProviderErrorProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'an array' => [['access_denied']];
        yield 'nothing but control characters' => ["\n\t"];
        yield 'not valid UTF-8' => ["\xC3\x28"];
    }

    /**
     * An `error` with nothing usable in it is not reported as a refusal — there
     * would be nothing to report — but it still ends the callback rather than
     * handing it back to the entry point.
     */
    #[DataProvider('unusableProviderErrorProvider')]
    public function testAnUnusableProviderErrorFallsThroughToTheMissingCodeFailure(mixed $error): void
    {
        $request = new Request(query: ['state' => 'test_state', 'error' => $error]);
        $this->setSessionOnRequest($request);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Missing or invalid code');
        $this->authenticator->authenticate($request);
    }

    public function testTheProvidersErrorTextIsCappedAndCleanedBeforeItIsLogged(): void
    {
        $request = new Request(query: [
            'state' => 'test_state',
            'error' => 'access_denied',
            'error_description' => "line one\r\nline two\x1b[31m".str_repeat('a', 10000),
        ]);
        $this->setSessionOnRequest($request);

        try {
            $this->authenticator->authenticate($request);
        } catch (ProviderErrorException $thrown) {
            $logged = $this->logger->singleRecord()['context']['error_description'] ?? null;
            $this->assertIsString($logged);
            // Exact, not <=: an off-by-one in the cap has to fail here.
            $this->assertSame(200, mb_strlen($logged));
            $this->assertSame(0, preg_match('/[[:cntrl:]]/', $logged), 'No forged log records, no terminal escapes');
            $this->assertStringContainsString('line one line two', $logged, 'A run of control characters reads as one space');
            $this->assertSame($logged, $thrown->getErrorDescription(), 'One sanitized value, two consumers');

            return;
        }
        $this->fail('Expected ProviderErrorException');
    }

    public function testCleanProviderTextIsPassedThroughUnchanged(): void
    {
        $request = new Request(query: [
            'state' => 'test_state',
            'error' => 'access_denied',
            'error_description' => 'Consent was not granted',
        ]);
        $this->setSessionOnRequest($request);

        try {
            $this->authenticator->authenticate($request);
        } catch (ProviderErrorException $thrown) {
            $this->assertSame('Consent was not granted', $thrown->getErrorDescription());

            return;
        }
        $this->fail('Expected ProviderErrorException');
    }

    /**
     * `?state[]=x` makes `InputBag::get()` throw Symfony's `BadRequestException`.
     * A method whose whole job is to end a callback cleanly is not where a
     * framework exception should escape from.
     */
    public function testAnArrayStateIsRejectedAsAnInvalidState(): void
    {
        $request = new Request(query: ['state' => ['test_state'], 'code' => 'test_code']);
        $this->setSessionOnRequest($request);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid state');
        $this->authenticator->authenticate($request);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unusableStoredStateProvider(): iterable
    {
        yield 'never stored' => [null];
        yield 'stored empty' => [''];
        yield 'not a string' => [['test_state']];
    }

    /**
     * Without the guard, an empty stored state and an empty query state compare
     * equal and the callback passes. The safety is local here, not emergent from
     * whatever the controller happened to write.
     */
    #[DataProvider('unusableStoredStateProvider')]
    public function testAnUnusableStoredStateIsAnInvalidState(mixed $stored): void
    {
        $request = new Request(query: ['state' => '', 'code' => 'test_code']);
        $stubSession = $this->createStub(SessionInterface::class);
        $stubSession->method('remove')->willReturnMap([
            ['oauth2provider', 'test_provider_1'],
            ['oauth2state', $stored],
            ['oauth2nonce', 'test_nonce'],
        ]);
        $request->setSession($stubSession);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid state');
        $this->authenticator->authenticate($request);
    }

    /**
     * A refusal already says why, and in what terms the application should answer.
     * Wrapping it would replace a 403 the user caused by clicking Cancel with a 500
     * somebody gets paged for.
     */
    public function testTheProviderErrorLeavesOnAuthenticationFailureUnwrapped(): void
    {
        $providerError = new ProviderErrorException('access_denied');

        try {
            $this->authenticator->onAuthenticationFailure(
                new Request(),
                new AuthenticationException('sanitised', 0, $providerError),
            );
        } catch (\Throwable $thrown) {
            $this->assertNotInstanceOf(AuthenticationException::class, $thrown);
            // The SemVer promise: everything already catching the bundle's login
            // failure keeps catching this one. Both asserted before assertSame()
            // below narrows the type and makes them hold statically.
            $this->assertInstanceOf(AuthenticationFailedException::class, $thrown);
            $this->assertSame($providerError, $thrown, 'Rethrown as it stands, not rebuilt');
            $this->assertSame(403, $thrown->getStatusCode());
            $this->assertSame([], $this->logger->records, 'Already logged in validateClaims()');

            return;
        }
        $this->fail('Expected ProviderErrorException');
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

    /**
     * A real session where the stub above will not do: asserting that a one-time
     * value is gone needs a `has()` that answers truthfully.
     */
    private function realSessionOnRequest(Request $request): Session
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('oauth2provider', 'test_provider_1');
        $session->set('oauth2state', 'test_state');
        $session->set('oauth2nonce', 'test_nonce');
        $request->setSession($session);

        return $session;
    }

    /**
     * The property above is deliberately typed as the abstract class, so the fixture
     * method exposing the protected helper needs a concrete local.
     */
    private function fixtureAuthenticator(): TestAuthenticator
    {
        $authenticator = new TestAuthenticator($this->stubProviderManager);
        $authenticator->setLogger($this->logger);

        return $authenticator;
    }

    private function requestWithSession(?string $targetPath): Request
    {
        $request = new Request();
        $session = new Session(new MockArraySessionStorage());

        if (null !== $targetPath) {
            $session->set('_security.main.target_path', $targetPath);
        }

        $request->setSession($session);

        return $request;
    }

    public function testTheRequestedPageIsReturnedToAndThenForgotten(): void
    {
        $request = $this->requestWithSession('/admin/reports');

        $response = $this->fixtureAuthenticator()->callCreateTargetPathRedirect($request, 'main', '/dashboard');

        $this->assertSame('/admin/reports', $response->getTargetUrl());
        // Cleared, so a later visit to the login link does not replay it.
        $this->assertFalse($request->getSession()->has('_security.main.target_path'));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function noTargetPathProvider(): iterable
    {
        yield 'nothing saved' => [null];
        yield 'saved but empty' => [''];
    }

    #[DataProvider('noTargetPathProvider')]
    public function testTheFallbackIsUsedWhenNoPageWasRequested(?string $targetPath): void
    {
        // A user who went to the login link directly, rather than being sent there.
        $request = $this->requestWithSession($targetPath);

        $response = $this->fixtureAuthenticator()->callCreateTargetPathRedirect($request, 'main', '/dashboard');

        $this->assertSame('/dashboard', $response->getTargetUrl());
    }

    public function testTheTargetPathIsReadForTheRightFirewall(): void
    {
        $request = $this->requestWithSession('/admin/reports');

        $response = $this->fixtureAuthenticator()->callCreateTargetPathRedirect($request, 'other_firewall', '/dashboard');

        $this->assertSame('/dashboard', $response->getTargetUrl());
        $this->assertTrue($request->getSession()->has('_security.main.target_path'), 'Another firewall\'s target path is left alone');
    }

    public function testATargetNamedOnTheLoginLinkIsUsedWhenNothingWasDenied(): void
    {
        // The case the firewall cannot cover: the user was never refused anything, so
        // Symfony saved nothing. They followed a login link that named where to go.
        $request = $this->requestWithSession(null);
        $request->getSession()->set(OpenIdLoginAuthenticator::TARGET_PATH_SESSION_KEY, '/admin/reports');

        $response = $this->fixtureAuthenticator()->callCreateTargetPathRedirect($request, 'main', '/dashboard');

        $this->assertSame('/admin/reports', $response->getTargetUrl());
        $this->assertFalse($request->getSession()->has(OpenIdLoginAuthenticator::TARGET_PATH_SESSION_KEY), 'Consumed, so it cannot replay');
    }

    public function testTheDeniedPageWinsOverATargetNamedOnTheLink(): void
    {
        // Both present: the firewall's record is what the user was actually stopped
        // from reaching, so it is the more faithful answer.
        $request = $this->requestWithSession('/admin/denied-page');
        $request->getSession()->set(OpenIdLoginAuthenticator::TARGET_PATH_SESSION_KEY, '/admin/reports');

        $response = $this->fixtureAuthenticator()->callCreateTargetPathRedirect($request, 'main', '/dashboard');

        $this->assertSame('/admin/denied-page', $response->getTargetUrl());
        // Both cleared, or the unused one would resurface on a later login.
        $this->assertFalse($request->getSession()->has('_security.main.target_path'));
        $this->assertFalse($request->getSession()->has(OpenIdLoginAuthenticator::TARGET_PATH_SESSION_KEY));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unusableNamedTargetProvider(): iterable
    {
        yield 'empty' => [''];
        // Nothing writes a non-string, but the session is shared with the application.
        yield 'not a string' => [['/admin/reports']];
    }

    #[DataProvider('unusableNamedTargetProvider')]
    public function testAnUnusableNamedTargetFallsBack(mixed $stored): void
    {
        $request = $this->requestWithSession(null);
        $request->getSession()->set(OpenIdLoginAuthenticator::TARGET_PATH_SESSION_KEY, $stored);

        $response = $this->fixtureAuthenticator()->callCreateTargetPathRedirect($request, 'main', '/dashboard');

        $this->assertSame('/dashboard', $response->getTargetUrl());
        $this->assertFalse($request->getSession()->has(OpenIdLoginAuthenticator::TARGET_PATH_SESSION_KEY));
    }
}

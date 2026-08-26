<?php

namespace ItkDev\OpenIdConnectBundle\Tests\DependencyInjection;

use ItkDev\OpenIdConnectBundle\DependencyInjection\Configuration;
use ItkDev\OpenIdConnectBundle\Log\AuthenticationAuditLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

class ConfigurationTest extends TestCase
{
    private Processor $processor;
    private Configuration $configuration;

    protected function setUp(): void
    {
        $this->processor = new Processor();
        $this->configuration = new Configuration();
    }

    private function getMinimalConfig(): array
    {
        return [
            'cache_options' => [
                'cache_pool' => 'cache.app',
            ],
            'cli_login_options' => [
                'route' => 'my_route',
            ],
            'openid_providers' => [
                'provider1' => [
                    'options' => [
                        'metadata_url' => 'https://example.com/.well-known/openid-configuration',
                        'client_id' => 'my_id',
                        'client_secret' => 'my_secret',
                        'client_secret_expires_at' => '2027-01-31',
                        'redirect_uri' => 'https://app.example.org/callback_uri',
                    ],
                ],
            ],
        ];
    }

    public function testMinimalConfig(): void
    {
        $config = $this->processor->processConfiguration(
            $this->configuration,
            [$this->getMinimalConfig()]
        );

        $this->assertSame('cache.app', $config['cache_options']['cache_pool']);
        $this->assertSame('my_route', $config['cli_login_options']['route']);
        $this->assertNull($config['user_provider']);
        $this->assertArrayHasKey('provider1', $config['openid_providers']);

        $provider = $config['openid_providers']['provider1']['options'];
        $this->assertSame('https://example.com/.well-known/openid-configuration', $provider['metadata_url']);
        $this->assertSame('my_id', $provider['client_id']);
        $this->assertSame('my_secret', $provider['client_secret']);
        $this->assertSame(10, $provider['leeway']);
        $this->assertSame(86400, $provider['cache_duration']);
        $this->assertFalse($provider['allow_http']);

        // Logging defaults to the application logger.
        $this->assertNull($config['logging_options']['logger']);

        // The audit trail is off unless asked for: it records personal data, so
        // an upgrade must not switch it on.
        $this->assertFalse($config['audit_options']['enabled']);
        $this->assertNull($config['audit_options']['logger']);
        $this->assertSame(AuthenticationAuditLogger::IDENTIFIER_RAW, $config['audit_options']['identifier']);

        $this->assertSame('2027-01-31', $provider['client_secret_expires_at']);
        $this->assertSame(30, $config['secret_expiry_options']['warning_days']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unparseableLiteralDateProvider(): iterable
    {
        yield 'prose' => ['whenever'];
        yield 'transposed' => ['31-02-2027 25:00'];
        yield 'nonsense' => ['not-a-date'];
        // Only the exact '' fixture is exempt, so this is a typo the build catches.
        yield 'whitespace only' => ['   '];
    }

    /**
     * A typo in a literal date still fails the build.
     *
     * Environment variable *contents* cannot be checked while compiling, but a
     * hardcoded value can be, and that is worth keeping: the alternative is a typo
     * degrading to a logged `unknown` that somebody has to notice.
     */
    #[DataProvider('unparseableLiteralDateProvider')]
    public function testClientSecretExpiresAtRejectsUnparseableLiterals(string $date): void
    {
        $input = $this->getMinimalConfig();
        $input['openid_providers']['provider1']['options']['client_secret_expires_at'] = $date;

        $this->expectException(InvalidConfigurationException::class);

        $this->processor->processConfiguration($this->configuration, [$input]);
    }

    public function testClientSecretExpiresAtToleratesAnEmptyString(): void
    {
        // '' is the fixture Symfony substitutes for a string env var while compiling,
        // so it has to pass here; the checker reports it at runtime. An explicit null
        // is no longer tolerated — see testANonStringExpiryDateIsRejected. It used to
        // mean "not configured", which is not a thing a required option has.
        $input = $this->getMinimalConfig();
        $input['openid_providers']['provider1']['options']['client_secret_expires_at'] = '';

        $config = $this->processor->processConfiguration($this->configuration, [$input]);

        $this->assertSame('', $config['openid_providers']['provider1']['options']['client_secret_expires_at']);
    }

    public function testClientSecretExpiresAtAccepted(): void
    {
        $input = $this->getMinimalConfig();
        $input['openid_providers']['provider1']['options']['client_secret_expires_at'] = '2027-01-31';
        $input['secret_expiry_options'] = ['warning_days' => 14];

        $config = $this->processor->processConfiguration($this->configuration, [$input]);

        $this->assertSame('2027-01-31', $config['openid_providers']['provider1']['options']['client_secret_expires_at']);
        $this->assertSame(14, $config['secret_expiry_options']['warning_days']);
    }

    public function testWarningDaysAcceptsZero(): void
    {
        // A valid choice: warn only once the secret has actually expired.
        $input = $this->getMinimalConfig();
        $input['secret_expiry_options'] = ['warning_days' => 0];

        $config = $this->processor->processConfiguration($this->configuration, [$input]);

        $this->assertSame(0, $config['secret_expiry_options']['warning_days']);
    }

    public function testWarningDaysRejectsNegativeValues(): void
    {
        $input = $this->getMinimalConfig();
        $input['secret_expiry_options'] = ['warning_days' => -1];

        $this->expectException(InvalidConfigurationException::class);

        $this->processor->processConfiguration($this->configuration, [$input]);
    }

    public function testAuditOptionsAccepted(): void
    {
        $input = $this->getMinimalConfig();
        $input['audit_options'] = [
            'enabled' => true,
            'logger' => 'monolog.logger.openid_connect_audit',
            'identifier' => AuthenticationAuditLogger::IDENTIFIER_HASHED,
        ];

        $config = $this->processor->processConfiguration($this->configuration, [$input]);

        $this->assertTrue($config['audit_options']['enabled']);
        $this->assertSame('monolog.logger.openid_connect_audit', $config['audit_options']['logger']);
        $this->assertSame(AuthenticationAuditLogger::IDENTIFIER_HASHED, $config['audit_options']['identifier']);
    }

    public function testAuditOptionsAcceptsBothIdentifierModes(): void
    {
        foreach ([AuthenticationAuditLogger::IDENTIFIER_RAW, AuthenticationAuditLogger::IDENTIFIER_HASHED] as $mode) {
            $input = $this->getMinimalConfig();
            $input['audit_options'] = ['identifier' => $mode];

            $config = $this->processor->processConfiguration($this->configuration, [$input]);

            $this->assertSame($mode, $config['audit_options']['identifier']);
        }
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidIdentifierModeProvider(): iterable
    {
        yield 'unknown word' => ['encrypted'];
        yield 'wrong case' => ['RAW'];
        yield 'not a string' => [123];
        yield 'explicit null' => [null];
        // An environment variable would arrive as this fixture while compiling, and
        // rejecting it is the point: the HMAC key is chosen then, so the mode has to
        // be a literal. Varying it per environment is what `when@prod:` is for.
        yield 'empty (the env placeholder fixture)' => [''];
    }

    #[DataProvider('invalidIdentifierModeProvider')]
    public function testAuditOptionsRejectsUnknownIdentifierMode(mixed $identifier): void
    {
        $input = $this->getMinimalConfig();
        $input['audit_options'] = ['identifier' => $identifier];

        $this->expectException(InvalidConfigurationException::class);

        $this->processor->processConfiguration($this->configuration, [$input]);
    }

    public function testLoggingOptionsAccepted(): void
    {
        $input = $this->getMinimalConfig();
        $input['logging_options'] = ['logger' => 'monolog.logger.openid_connect'];

        $config = $this->processor->processConfiguration($this->configuration, [$input]);

        $this->assertSame('monolog.logger.openid_connect', $config['logging_options']['logger']);
    }

    public function testLoggingOptionsRejectsEmptyLogger(): void
    {
        $input = $this->getMinimalConfig();
        $input['logging_options'] = ['logger' => ''];

        $this->expectException(InvalidConfigurationException::class);

        $this->processor->processConfiguration($this->configuration, [$input]);
    }

    public function testFullConfig(): void
    {
        $input = $this->getMinimalConfig();
        $input['user_provider'] = 'my_user_provider';
        $input['openid_providers']['provider1']['options']['leeway'] = 30;
        $input['openid_providers']['provider1']['options']['cache_duration'] = 3600;
        $input['openid_providers']['provider1']['options']['redirect_uri'] = 'https://app.example.org/callback';
        $input['openid_providers']['provider1']['options']['allow_http'] = true;

        $config = $this->processor->processConfiguration(
            $this->configuration,
            [$input]
        );

        $this->assertSame('my_user_provider', $config['user_provider']);

        $provider = $config['openid_providers']['provider1']['options'];
        $this->assertSame(30, $provider['leeway']);
        $this->assertSame(3600, $provider['cache_duration']);
        $this->assertSame('https://app.example.org/callback', $provider['redirect_uri']);
        $this->assertTrue($provider['allow_http']);
    }

    public function testRedirectRouteConfig(): void
    {
        $input = $this->getMinimalConfig();
        // Mutually exclusive with redirect_uri, which the minimal config sets.
        unset($input['openid_providers']['provider1']['options']['redirect_uri']);
        $input['openid_providers']['provider1']['options']['redirect_route'] = 'my_redirect_route';

        $config = $this->processor->processConfiguration(
            $this->configuration,
            [$input]
        );

        $provider = $config['openid_providers']['provider1']['options'];
        $this->assertSame('my_redirect_route', $provider['redirect_route']);
    }

    public function testBothRedirectUriAndRouteThrows(): void
    {
        $input = $this->getMinimalConfig();
        $input['openid_providers']['provider1']['options']['redirect_uri'] = 'https://app.example.org/callback';
        $input['openid_providers']['provider1']['options']['redirect_route'] = 'my_route';

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Only one of redirect_uri or redirect_route must be set.');

        $this->processor->processConfiguration(
            $this->configuration,
            [$input]
        );
    }

    public function testHttpClientOptionsAccepted(): void
    {
        $input = $this->getMinimalConfig();
        $input['openid_providers']['provider1']['options']['http_client_options'] = [
            'timeout' => 2.5,
            'proxy' => 'http://proxy:8080',
            'verify' => true,
        ];

        $config = $this->processor->processConfiguration(
            $this->configuration,
            [$input]
        );

        $httpClientOptions = $config['openid_providers']['provider1']['options']['http_client_options'];
        $this->assertSame(2.5, $httpClientOptions['timeout']);
        $this->assertSame('http://proxy:8080', $httpClientOptions['proxy']);
        $this->assertTrue($httpClientOptions['verify']);
    }

    public function testHttpClientOptionsDefaultsApplied(): void
    {
        $config = $this->processor->processConfiguration(
            $this->configuration,
            [$this->getMinimalConfig()]
        );

        $providerOptions = $config['openid_providers']['provider1']['options'];
        // The block carries a sensible default timeout so an omitted input still
        // protects workers from a hung IdP. proxy/verify have no default and so
        // stay absent (Guzzle's own defaults apply).
        $this->assertSame(30.0, $providerOptions['http_client_options']['timeout']);
        $this->assertArrayNotHasKey('proxy', $providerOptions['http_client_options']);
        $this->assertArrayNotHasKey('verify', $providerOptions['http_client_options']);
    }

    public function testHttpClientOptionsRejectsUnknownKey(): void
    {
        $input = $this->getMinimalConfig();
        $input['openid_providers']['provider1']['options']['http_client_options'] = [
            'foo' => 1,
        ];

        $this->expectException(InvalidConfigurationException::class);

        $this->processor->processConfiguration(
            $this->configuration,
            [$input]
        );
    }

    public function testProviderKeysAreNotNormalized(): void
    {
        $input = $this->getMinimalConfig();
        $input['openid_providers']['my-provider'] = $input['openid_providers']['provider1'];
        unset($input['openid_providers']['provider1']);

        $config = $this->processor->processConfiguration(
            $this->configuration,
            [$input]
        );

        // Provider keys are part of the public contract ('my-provider' and
        // 'my_provider' are distinct providers), so dashes must survive
        // config processing instead of being normalized to underscores.
        $this->assertArrayHasKey('my-provider', $config['openid_providers']);
        $this->assertArrayNotHasKey('my_provider', $config['openid_providers']);
    }

    /**
     * The definition itself must be free of deprecations.
     *
     * Symfony reports a contradictory definition — a required node that also carries
     * a default, say — by deprecating it rather than refusing it, and
     * `trigger_deprecation()` raises that with `@`, which PHPUnit's own
     * `failOnDeprecation` respects and therefore never sees. A handler installed here
     * does see it. Otherwise the first report comes from a consuming application's
     * console, which is where this one was found.
     */
    public function testTheDefinitionEmitsNoDeprecations(): void
    {
        $deprecations = [];
        // All four arguments are forwarded: the handler being wrapped is PHPUnit's,
        // whose __invoke() requires file and line.
        $previous = set_error_handler(static function (int $level, string $message, string $file = '', int $line = 0) use (&$deprecations, &$previous): bool {
            if (\E_USER_DEPRECATED === $level) {
                $deprecations[] = $message;

                return true;
            }

            return null !== $previous && false !== ($previous)($level, $message, $file, $line);
        });

        try {
            $this->processor->processConfiguration($this->configuration, [$this->getMinimalConfig()]);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $deprecations);
    }

    /**
     * The value a reader would most likely write.
     *
     * `client_secret_expires_at: 2027-01-31` without quotes is the integer
     * 1801353600 by the time configuration sees it. Accepting it would discard the
     * date and leave the provider unmonitored with nothing logged anywhere, which is
     * the exact outcome this option exists to prevent.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function nonStringDateProvider(): iterable
    {
        yield 'unquoted date, read as a timestamp' => [1801353600];
        yield 'digits' => [20270131];
        yield 'boolean' => [true];
        yield 'explicit null, which isRequired() accepts' => [null];
    }

    #[DataProvider('nonStringDateProvider')]
    public function testANonStringExpiryDateIsRejected(mixed $configured): void
    {
        $input = $this->getMinimalConfig();
        $input['openid_providers']['provider1']['options']['client_secret_expires_at'] = $configured;

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('client_secret_expires_at must be a string');

        $this->processor->processConfiguration($this->configuration, [$input]);
    }

    /**
     * Optional on purpose. A required key can force a value, never a correct one, and
     * it cannot be scoped to the environment where the real secret lives — Symfony
     * compiles a container per environment, so a required node has to appear in all of
     * them. What that produces is a date in a committed default, which reports `ok`
     * forever while monitoring nothing. Unset is the honest state, and it is visible:
     * the provider reports `unknown`.
     */
    public function testTheExpiryDateIsOptional(): void
    {
        $input = $this->getMinimalConfig();
        unset($input['openid_providers']['provider1']['options']['client_secret_expires_at']);

        $config = $this->processor->processConfiguration($this->configuration, [$input]);

        $this->assertArrayNotHasKey('client_secret_expires_at', $config['openid_providers']['provider1']['options']);
    }

    public function testMultipleProviders(): void
    {
        $input = $this->getMinimalConfig();
        $input['openid_providers']['provider2'] = [
            'options' => [
                'metadata_url' => 'https://other-provider.example.org/.well-known/openid-configuration',
                'client_id' => 'other_id',
                'client_secret' => 'other_secret',
                'client_secret_expires_at' => '2028-06-30',
                'redirect_uri' => 'https://app.example.org/other_callback',
            ],
        ];

        $config = $this->processor->processConfiguration(
            $this->configuration,
            [$input]
        );

        $this->assertCount(2, $config['openid_providers']);
        $this->assertArrayHasKey('provider1', $config['openid_providers']);
        $this->assertArrayHasKey('provider2', $config['openid_providers']);
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalidCallbackPathProvider(): iterable
    {
        yield 'not a string' => [42, 'callback_path must be a string'];
        yield 'null' => [null, 'callback_path must be a string'];
        yield 'no leading slash' => ['auth/callback', 'callback_path must start with "/"'];
        yield 'a full url' => ['https://app.example.org/auth/callback', 'callback_path must start with "/"'];
    }

    #[DataProvider('invalidCallbackPathProvider')]
    public function testAnInvalidCallbackPathIsRejected(mixed $configured, string $expectedMessage): void
    {
        $input = $this->getMinimalConfig();
        $input['openid_providers']['provider1']['options']['callback_path'] = $configured;

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($expectedMessage);

        $this->processor->processConfiguration($this->configuration, [$input]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validCallbackPathProvider(): iterable
    {
        yield 'a path' => ['/auth/callback'];
        yield 'the root' => ['/'];
        // As on client_secret_expires_at: '' is the fixture Symfony substitutes for a
        // string environment variable while compiling, so it must pass here.
        yield 'the environment variable fixture' => [''];
    }

    #[DataProvider('validCallbackPathProvider')]
    public function testAValidCallbackPathIsAccepted(string $configured): void
    {
        $input = $this->getMinimalConfig();
        $input['openid_providers']['provider1']['options']['callback_path'] = $configured;

        $config = $this->processor->processConfiguration($this->configuration, [$input]);

        $this->assertSame($configured, $config['openid_providers']['provider1']['options']['callback_path']);
    }

    /**
     * A provider that declares no callback target cannot recognise a callback, so it
     * could never complete a login.
     */
    public function testAProviderMustDeclareACallbackTarget(): void
    {
        $input = $this->getMinimalConfig();
        unset($input['openid_providers']['provider1']['options']['redirect_uri']);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('One of redirect_uri, redirect_route or callback_path must be set');

        $this->processor->processConfiguration($this->configuration, [$input]);
    }

    public function testCallbackPathAloneSatisfiesTheRequirement(): void
    {
        $input = $this->getMinimalConfig();
        unset($input['openid_providers']['provider1']['options']['redirect_uri']);
        $input['openid_providers']['provider1']['options']['callback_path'] = '/auth/callback';

        $config = $this->processor->processConfiguration($this->configuration, [$input]);

        $this->assertSame('/auth/callback', $config['openid_providers']['provider1']['options']['callback_path']);
    }

    public function testPkceDefaultsToOn(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [$this->getMinimalConfig()]);

        $this->assertTrue($config['openid_providers']['provider1']['options']['pkce']);
    }

    public function testPkceCanBeTurnedOff(): void
    {
        $input = $this->getMinimalConfig();
        $input['openid_providers']['provider1']['options']['pkce'] = false;

        $config = $this->processor->processConfiguration($this->configuration, [$input]);

        $this->assertFalse($config['openid_providers']['provider1']['options']['pkce']);
    }

    public function testScopesDefaultToTheOpenIdConnectBasics(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [$this->getMinimalConfig()]);

        $this->assertSame(['openid', 'email', 'profile'], $config['openid_providers']['provider1']['options']['scopes']);
    }

    public function testScopesCanBeConfigured(): void
    {
        $input = $this->getMinimalConfig();
        $input['openid_providers']['provider1']['options']['scopes'] = ['openid', 'profile', 'groups'];

        $config = $this->processor->processConfiguration($this->configuration, [$input]);

        $this->assertSame(['openid', 'profile', 'groups'], $config['openid_providers']['provider1']['options']['scopes']);
    }

    /**
     * @return iterable<string, array{string, string[]}>
     */
    public static function scopeStringProvider(): iterable
    {
        yield 'single space' => ['openid profile groups', ['openid', 'profile', 'groups']];
        yield 'surrounding whitespace' => ['  openid profile  ', ['openid', 'profile']];
        yield 'runs of whitespace' => ["openid\t\tprofile\ngroups", ['openid', 'profile', 'groups']];
        yield 'one scope' => ['openid', ['openid']];
    }

    /**
     * An environment variable can only carry a scalar, so the space-delimited form
     * RFC 6749 §3.3 already uses on the wire is accepted here too.
     *
     * @param string[] $expected
     */
    #[DataProvider('scopeStringProvider')]
    public function testScopesAcceptASpaceSeparatedString(string $configured, array $expected): void
    {
        $input = $this->getMinimalConfig();
        $input['openid_providers']['provider1']['options']['scopes'] = $configured;

        $config = $this->processor->processConfiguration($this->configuration, [$input]);

        $this->assertSame($expected, $config['openid_providers']['provider1']['options']['scopes']);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function scopesWithoutOpenIdProvider(): iterable
    {
        yield 'a list' => [['email', 'profile']];
        yield 'a string' => ['email profile'];
    }

    #[DataProvider('scopesWithoutOpenIdProvider')]
    public function testScopesMustIncludeOpenId(mixed $configured): void
    {
        $input = $this->getMinimalConfig();
        $input['openid_providers']['provider1']['options']['scopes'] = $configured;

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('scopes must include openid: without it the provider returns no ID token.');
        $this->processor->processConfiguration($this->configuration, [$input]);
    }

    public function testScopesCannotBeEmpty(): void
    {
        $input = $this->getMinimalConfig();
        $input['openid_providers']['provider1']['options']['scopes'] = [];

        $this->expectException(InvalidConfigurationException::class);
        $this->processor->processConfiguration($this->configuration, [$input]);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function negativeDurationProvider(): iterable
    {
        yield 'leeway' => ['leeway', -1];
        yield 'cache_duration' => ['cache_duration', -1];
    }

    /**
     * Rejected while the container compiles rather than at the first login that
     * needs the value.
     */
    #[DataProvider('negativeDurationProvider')]
    public function testDurationsCannotBeNegative(string $option, int $value): void
    {
        $input = $this->getMinimalConfig();
        $input['openid_providers']['provider1']['options'][$option] = $value;

        $this->expectException(InvalidConfigurationException::class);
        $this->processor->processConfiguration($this->configuration, [$input]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function zeroableDurationProvider(): iterable
    {
        yield 'leeway' => ['leeway'];
        yield 'cache_duration' => ['cache_duration'];
    }

    /**
     * Zero is a coherent setting for both: no clock-skew window, and no caching.
     */
    #[DataProvider('zeroableDurationProvider')]
    public function testDurationsMayBeZero(string $option): void
    {
        $input = $this->getMinimalConfig();
        $input['openid_providers']['provider1']['options'][$option] = 0;

        $config = $this->processor->processConfiguration($this->configuration, [$input]);

        $this->assertSame(0, $config['openid_providers']['provider1']['options'][$option]);
    }
}

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

        // No expiry date yet, and a 30-day default warning window.
        $this->assertNull($provider['client_secret_expires_at']);
        $this->assertSame(30, $config['secret_expiry_options']['warning_days']);
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

    /**
     * @return iterable<string, array{string}>
     */
    public static function unparseableDateProvider(): iterable
    {
        yield 'prose' => ['whenever'];
        yield 'transposed' => ['31-02-2027 25:00'];
        yield 'nonsense' => ['not-a-date'];
    }

    /**
     * Validated as the container compiles, so a typo is a build failure rather
     * than a silent "unknown" that never warns about anything.
     */
    #[DataProvider('unparseableDateProvider')]
    public function testClientSecretExpiresAtRejectsUnparseableDates(string $date): void
    {
        $input = $this->getMinimalConfig();
        $input['openid_providers']['provider1']['options']['client_secret_expires_at'] = $date;

        $this->expectException(InvalidConfigurationException::class);

        $this->processor->processConfiguration($this->configuration, [$input]);
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

    public function testAuditOptionsRejectsUnknownIdentifierMode(): void
    {
        $input = $this->getMinimalConfig();
        $input['audit_options'] = ['identifier' => 'encrypted'];

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

    public function testMultipleProviders(): void
    {
        $input = $this->getMinimalConfig();
        $input['openid_providers']['provider2'] = [
            'options' => [
                'metadata_url' => 'https://other-provider.example.org/.well-known/openid-configuration',
                'client_id' => 'other_id',
                'client_secret' => 'other_secret',
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
}

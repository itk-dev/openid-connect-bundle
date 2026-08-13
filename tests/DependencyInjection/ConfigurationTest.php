<?php

namespace ItkDev\OpenIdConnectBundle\Tests\DependencyInjection;

use ItkDev\OpenIdConnectBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
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

        // Logging defaults: application logger, error level.
        $this->assertNull($config['logging_options']['logger']);
        $this->assertSame(LogLevel::ERROR, $config['logging_options']['level']);
    }

    public function testLoggingOptionsAccepted(): void
    {
        $input = $this->getMinimalConfig();
        $input['logging_options'] = [
            'logger' => 'monolog.logger.openid_connect',
            'level' => LogLevel::CRITICAL,
        ];

        $config = $this->processor->processConfiguration($this->configuration, [$input]);

        $this->assertSame('monolog.logger.openid_connect', $config['logging_options']['logger']);
        $this->assertSame(LogLevel::CRITICAL, $config['logging_options']['level']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function psrLogLevelProvider(): iterable
    {
        yield 'emergency' => [LogLevel::EMERGENCY];
        yield 'alert' => [LogLevel::ALERT];
        yield 'critical' => [LogLevel::CRITICAL];
        yield 'error' => [LogLevel::ERROR];
        yield 'warning' => [LogLevel::WARNING];
        yield 'notice' => [LogLevel::NOTICE];
        yield 'info' => [LogLevel::INFO];
        yield 'debug' => [LogLevel::DEBUG];
    }

    /**
     * Every PSR-3 level must be selectable, so the allowed list cannot silently
     * lose one.
     */
    #[DataProvider('psrLogLevelProvider')]
    public function testLoggingOptionsAcceptsEveryPsrLevel(string $level): void
    {
        $input = $this->getMinimalConfig();
        $input['logging_options'] = ['level' => $level];

        $config = $this->processor->processConfiguration($this->configuration, [$input]);

        $this->assertSame($level, $config['logging_options']['level']);
    }

    public function testLoggingOptionsRejectsUnknownLevel(): void
    {
        $input = $this->getMinimalConfig();
        $input['logging_options'] = ['level' => 'chatty'];

        $this->expectException(InvalidConfigurationException::class);

        $this->processor->processConfiguration($this->configuration, [$input]);
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

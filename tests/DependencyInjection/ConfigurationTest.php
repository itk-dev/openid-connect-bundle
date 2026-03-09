<?php

namespace ItkDev\OpenIdConnectBundle\Tests\DependencyInjection;

use ItkDev\OpenIdConnectBundle\DependencyInjection\Configuration;
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
        $this->assertFalse($provider['allow_http']);
    }

    public function testFullConfig(): void
    {
        $input = $this->getMinimalConfig();
        $input['user_provider'] = 'my_user_provider';
        $input['openid_providers']['provider1']['options']['leeway'] = 30;
        $input['openid_providers']['provider1']['options']['redirect_uri'] = 'https://app.com/callback';
        $input['openid_providers']['provider1']['options']['allow_http'] = true;

        $config = $this->processor->processConfiguration(
            $this->configuration,
            [$input]
        );

        $this->assertSame('my_user_provider', $config['user_provider']);

        $provider = $config['openid_providers']['provider1']['options'];
        $this->assertSame(30, $provider['leeway']);
        $this->assertSame('https://app.com/callback', $provider['redirect_uri']);
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
        $input['openid_providers']['provider1']['options']['redirect_uri'] = 'https://app.com/callback';
        $input['openid_providers']['provider1']['options']['redirect_route'] = 'my_route';

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Only one of redirect_uri or redirect_route must be set.');

        $this->processor->processConfiguration(
            $this->configuration,
            [$input]
        );
    }

    public function testMultipleProviders(): void
    {
        $input = $this->getMinimalConfig();
        $input['openid_providers']['provider2'] = [
            'options' => [
                'metadata_url' => 'https://other.com/.well-known/openid-configuration',
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

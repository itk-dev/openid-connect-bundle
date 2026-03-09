<?php

namespace ItkDev\OpenIdConnectBundle\Tests\Security;

use ItkDev\OpenIdConnect\Security\OpenIdConfigurationProvider;
use ItkDev\OpenIdConnectBundle\Exception\InvalidProviderException;
use ItkDev\OpenIdConnectBundle\Security\OpenIdConfigurationProviderManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Routing\RouterInterface;

class OpenIdConfigurationProviderManagerTest extends TestCase
{
    private RouterInterface $stubRouter;

    protected function setUp(): void
    {
        $this->stubRouter = $this->createStub(RouterInterface::class);
    }

    private function getBaseProviderConfig(): array
    {
        return [
            'metadata_url' => 'https://example.com/.well-known/openid-configuration',
            'client_id' => 'test_id',
            'client_secret' => 'test_secret',
        ];
    }

    private function createManager(array $providers, array $defaultOptions = []): OpenIdConfigurationProviderManager
    {
        $config = [
            'default_providers_options' => array_merge(
                ['cacheItemPool' => new ArrayAdapter()],
                $defaultOptions
            ),
            'providers' => $providers,
        ];

        return new OpenIdConfigurationProviderManager($this->stubRouter, $config);
    }

    public function testGetProviderKeys(): void
    {
        $manager = $this->createManager([
            'provider_a' => $this->getBaseProviderConfig(),
            'provider_b' => $this->getBaseProviderConfig(),
        ]);

        $this->assertSame(['provider_a', 'provider_b'], $manager->getProviderKeys());
    }

    public function testGetProviderThrowsOnInvalidKey(): void
    {
        $manager = $this->createManager([]);

        $this->expectException(InvalidProviderException::class);
        $this->expectExceptionMessage('Invalid provider: nonexistent');

        $manager->getProvider('nonexistent');
    }

    public function testGetProviderWithRedirectRoute(): void
    {
        $this->stubRouter
            ->method('generate')
            ->willReturn('https://app.com/callback');

        $manager = $this->createManager([
            'test' => $this->getBaseProviderConfig() + [
                'redirect_route' => 'my_route',
                'redirect_route_parameters' => ['param' => 'value'],
            ],
        ]);

        $provider = $manager->getProvider('test');
        $this->assertInstanceOf(OpenIdConfigurationProvider::class, $provider);
    }

    public function testGetProviderWithRedirectRouteNoParameters(): void
    {
        $this->stubRouter
            ->method('generate')
            ->willReturn('https://app.com/callback');

        $manager = $this->createManager([
            'test' => $this->getBaseProviderConfig() + [
                'redirect_route' => 'my_route',
            ],
        ]);

        $provider = $manager->getProvider('test');
        $this->assertInstanceOf(OpenIdConfigurationProvider::class, $provider);
    }

    public function testGetProviderWithLeeway(): void
    {
        $manager = $this->createManager([
            'test' => $this->getBaseProviderConfig() + [
                'redirect_uri' => 'https://app.com/callback',
                'leeway' => 30,
            ],
        ]);

        $provider = $manager->getProvider('test');
        $this->assertInstanceOf(OpenIdConfigurationProvider::class, $provider);
    }

    public function testGetProviderWithAllowHttp(): void
    {
        $manager = $this->createManager([
            'test' => $this->getBaseProviderConfig() + [
                'redirect_uri' => 'https://app.com/callback',
                'allow_http' => true,
            ],
        ]);

        $provider = $manager->getProvider('test');
        $this->assertInstanceOf(OpenIdConfigurationProvider::class, $provider);
    }

    public function testGetProviderCachesInstance(): void
    {
        $manager = $this->createManager([
            'test' => $this->getBaseProviderConfig() + [
                'redirect_uri' => 'https://app.com/callback',
            ],
        ]);

        $provider1 = $manager->getProvider('test');
        $provider2 = $manager->getProvider('test');

        $this->assertSame($provider1, $provider2);
    }
}

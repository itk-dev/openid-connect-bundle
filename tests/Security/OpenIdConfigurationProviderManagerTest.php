<?php

namespace ItkDev\OpenIdConnectBundle\Tests\Security;

use GuzzleHttp\Client as GuzzleClient;
use ItkDev\OpenIdConnect\Security\OpenIdConfigurationProvider;
use ItkDev\OpenIdConnectBundle\Exception\InvalidProviderException;
use ItkDev\OpenIdConnectBundle\Security\OpenIdConfigurationProviderManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

class OpenIdConfigurationProviderManagerTest extends TestCase
{
    /** @var RouterInterface&Stub */
    private RouterInterface $stubRouter;

    protected function setUp(): void
    {
        $this->stubRouter = $this->createStub(RouterInterface::class);
    }

    /**
     * @return array{metadata_url: string, client_id: string, client_secret: string}
     */
    private function getBaseProviderConfig(): array
    {
        return [
            'metadata_url' => 'https://example.com/.well-known/openid-configuration',
            'client_id' => 'test_id',
            'client_secret' => 'test_secret',
        ];
    }

    /**
     * Test helper: callers build provider arrays from {@see getBaseProviderConfig()}
     * plus optional fields, so the parameter is intentionally typed loosely. The
     * production manager constructor has the precise array shape.
     *
     * @param array<string, array<string, mixed>> $providers
     * @param array<string, mixed>                $defaultOptions
     */
    private function createManager(array $providers, array $defaultOptions = []): OpenIdConfigurationProviderManager
    {
        $config = [
            'default_providers_options' => array_merge(
                ['cacheItemPool' => new ArrayAdapter()],
                $defaultOptions
            ),
            'providers' => $providers,
        ];

        // @phpstan-ignore argument.type (test helper relaxes the strict provider shape declared by the production constructor — callers build configs ad-hoc from getBaseProviderConfig() plus optional fields)
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
        // Expect the exact arguments so dropping the route parameters (or the
        // route itself) when building the redirect URI fails the test.
        $mockRouter = $this->createMock(RouterInterface::class);
        $mockRouter->expects($this->once())
            ->method('generate')
            ->with('my_route', ['param' => 'value'], UrlGeneratorInterface::ABSOLUTE_URL)
            ->willReturn('https://app.example.org/callback');
        $this->stubRouter = $mockRouter;

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
            ->willReturn('https://app.example.org/callback');

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
                'redirect_uri' => 'https://app.example.org/callback',
                'leeway' => 30,
            ],
        ]);

        $provider = $manager->getProvider('test');
        $this->assertInstanceOf(OpenIdConfigurationProvider::class, $provider);
    }

    public function testGetProviderWithCacheDuration(): void
    {
        $manager = $this->createManager([
            'test' => $this->getBaseProviderConfig() + [
                'redirect_uri' => 'https://app.example.org/callback',
                'cache_duration' => 3600,
            ],
        ]);

        $provider = $manager->getProvider('test');
        $this->assertInstanceOf(OpenIdConfigurationProvider::class, $provider);
    }

    public function testGetProviderWithAllowHttp(): void
    {
        $manager = $this->createManager([
            'test' => $this->getBaseProviderConfig() + [
                'redirect_uri' => 'https://app.example.org/callback',
                'allow_http' => true,
            ],
        ]);

        $provider = $manager->getProvider('test');
        $this->assertInstanceOf(OpenIdConfigurationProvider::class, $provider);
    }

    /**
     * Read a Guzzle 7 client option.
     *
     * Guzzle's getConfig() carries a @deprecated tag for the planned v8 removal,
     * but it remains the only public way to introspect a Client's effective
     * config in v7 — which is what league/oauth2-client mandates. The tests
     * below assert effective config, so we intentionally call the deprecated
     * accessor and silence the single phpstan diagnostic it produces.
     */
    private function getGuzzleConfig(GuzzleClient $client, string $option): mixed
    {
        // @phpstan-ignore method.deprecated (see docblock above)
        return $client->getConfig($option);
    }

    public function testGetProviderForwardsHttpClientOptions(): void
    {
        $manager = $this->createManager([
            'test' => $this->getBaseProviderConfig() + [
                'redirect_uri' => 'https://app.example.org/callback',
                'http_client_options' => [
                    'timeout' => 1.5,
                    'proxy' => 'http://proxy:8080',
                    'verify' => false,
                ],
            ],
        ]);

        $provider = $manager->getProvider('test');
        $httpClient = $provider->getHttpClient();

        $this->assertInstanceOf(GuzzleClient::class, $httpClient);
        $this->assertSame(1.5, $this->getGuzzleConfig($httpClient, 'timeout'));
        $this->assertSame('http://proxy:8080', $this->getGuzzleConfig($httpClient, 'proxy'));
        // verify is only forwarded by league when proxy is set.
        $this->assertFalse($this->getGuzzleConfig($httpClient, 'verify'));
    }

    public function testGetProviderWithoutHttpClientOptionsLeavesGuzzleDefaults(): void
    {
        $manager = $this->createManager([
            'test' => $this->getBaseProviderConfig() + [
                'redirect_uri' => 'https://app.example.org/callback',
            ],
        ]);

        $provider = $manager->getProvider('test');
        $httpClient = $provider->getHttpClient();

        $this->assertInstanceOf(GuzzleClient::class, $httpClient);
        // No timeout configured ⇒ Guzzle's getConfig returns null. Asserts no leak from our pass-through.
        $this->assertNull($this->getGuzzleConfig($httpClient, 'timeout'));
    }

    public function testGetProviderCachesInstance(): void
    {
        $manager = $this->createManager([
            'test' => $this->getBaseProviderConfig() + [
                'redirect_uri' => 'https://app.example.org/callback',
            ],
        ]);

        $provider1 = $manager->getProvider('test');
        $provider2 = $manager->getProvider('test');

        $this->assertSame($provider1, $provider2);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function pathDerivationProvider(): iterable
    {
        yield 'path of an absolute redirect_uri' => [['redirect_uri' => 'https://app.example.org/callback_uri'], '/callback_uri'];
        yield 'trailing slash removed' => [['redirect_uri' => 'https://app.example.org/callback_uri/'], '/callback_uri'];
        yield 'nested path' => [['redirect_uri' => 'https://app.example.org/auth/oidc/callback'], '/auth/oidc/callback'];
        yield 'query and fragment ignored' => [['redirect_uri' => 'https://app.example.org/callback_uri?x=1#f'], '/callback_uri'];
        // A redirect_uri naming only a host answers at the root.
        yield 'no path at all' => [['redirect_uri' => 'https://app.example.org'], '/'];
        yield 'bare root' => [['redirect_uri' => 'https://app.example.org/'], '/'];
        // callback_path exists for proxies that rewrite the external path, so it has
        // to win over the redirect_uri it contradicts.
        yield 'callback_path overrides redirect_uri' => [
            ['redirect_uri' => 'https://app.example.org/prefix/auth/callback', 'callback_path' => '/auth/callback'],
            '/auth/callback',
        ];
        yield 'callback_path is normalized too' => [['callback_path' => '/auth/callback/'], '/auth/callback'];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('pathDerivationProvider')]
    public function testRedirectUriPathsAreDerivedAndNormalized(array $options, string $expected): void
    {
        $manager = $this->createManager(['provider1' => $this->getBaseProviderConfig() + $options]);

        $this->assertSame(['provider1' => $expected], $manager->getRedirectUriPaths());
    }

    public function testARouteIsGeneratedAsAPathNotAUrl(): void
    {
        // ABSOLUTE_PATH, so that whatever a reverse proxy does to the host or scheme
        // cannot affect the comparison, and the router's base path is included.
        $router = $this->createMock(RouterInterface::class);
        $router->expects($this->once())
            ->method('generate')
            ->with('my_route', ['id' => '7'], UrlGeneratorInterface::ABSOLUTE_PATH)
            ->willReturn('/generated/callback');

        $config = [
            'default_providers_options' => [],
            'providers' => ['provider1' => $this->getBaseProviderConfig() + [
                'redirect_route' => 'my_route',
                'redirect_route_parameters' => ['id' => '7'],
            ]],
        ];

        $manager = new OpenIdConfigurationProviderManager($router, $config);

        $this->assertSame(['provider1' => '/generated/callback'], $manager->getRedirectUriPaths());
        // Memoized: supports() asks on every request through the firewall, and the
        // once() above is what holds that.
        $manager->getRedirectUriPaths();
    }

    public function testAProviderWithNoRedirectTargetIsAbsentRatherThanMatchingEverything(): void
    {
        $manager = $this->createManager([
            'with_path' => $this->getBaseProviderConfig() + ['redirect_uri' => 'https://app.example.org/callback_uri'],
            'without_path' => $this->getBaseProviderConfig(),
        ]);

        $this->assertSame(['with_path' => '/callback_uri'], $manager->getRedirectUriPaths());
    }

    public function testDerivingPathsDoesNotBuildProviders(): void
    {
        // Building a provider pulls in discovery, an HTTP client and a cache pool.
        // Nothing in this config could support that, so a successful call proves
        // supports() is not paying for it on every request.
        $manager = $this->createManager(['provider1' => [
            'metadata_url' => 'https://unreachable.invalid/.well-known/openid-configuration',
            'client_id' => 'id',
            'client_secret' => 'secret',
            'redirect_uri' => 'https://app.example.org/callback_uri',
        ]]);

        $this->assertSame(['provider1' => '/callback_uri'], $manager->getRedirectUriPaths());
    }
}

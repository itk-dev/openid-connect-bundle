<?php

namespace ItkDev\OpenIdConnectBundle\Tests\DependencyInjection;

use ItkDev\OpenIdConnectBundle\Command\UserLoginCommand;
use ItkDev\OpenIdConnectBundle\Controller\LoginController;
use ItkDev\OpenIdConnectBundle\DependencyInjection\ItkDevOpenIdConnectExtension;
use ItkDev\OpenIdConnectBundle\Security\CliLoginTokenAuthenticator;
use ItkDev\OpenIdConnectBundle\Security\OpenIdConfigurationProviderManager;
use ItkDev\OpenIdConnectBundle\Security\OpenIdLoginAuthenticator;
use ItkDev\OpenIdConnectBundle\Util\CliLoginHelper;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class ItkDevOpenIdConnectExtensionTest extends TestCase
{
    private function getBaseConfig(?string $userProvider = null): array
    {
        return [
            'cache_options' => [
                'cache_pool' => 'cache.app',
            ],
            'cli_login_options' => [
                'route' => 'test_route',
            ],
            'user_provider' => $userProvider,
            'openid_providers' => [
                'test_provider' => [
                    'options' => [
                        'metadata_url' => 'https://example.com/.well-known/openid-configuration',
                        'client_id' => 'test_id',
                        'client_secret' => 'test_secret',
                    ],
                ],
            ],
        ];
    }

    public function testLoad(): void
    {
        $extension = new ItkDevOpenIdConnectExtension();
        $container = new ContainerBuilder();

        $extension->load([$this->getBaseConfig()], $container);

        $this->assertTrue($container->hasDefinition(OpenIdConfigurationProviderManager::class));
        $this->assertTrue($container->hasDefinition(CliLoginHelper::class));
        $this->assertTrue($container->hasDefinition(UserLoginCommand::class));
        $this->assertTrue($container->hasDefinition(CliLoginTokenAuthenticator::class));
    }

    public function testLoggingDefaultsToApplicationLoggerAtErrorLevel(): void
    {
        $extension = new ItkDevOpenIdConnectExtension();
        $container = new ContainerBuilder();

        $extension->load([$this->getBaseConfig()], $container);

        foreach ([LoginController::class, CliLoginTokenAuthenticator::class] as $id) {
            $arguments = $container->getDefinition($id)->getArguments();
            $this->assertSame(LogLevel::ERROR, $arguments['$logLevel']);
            $this->assertArrayNotHasKey('$logger', $arguments, 'Without a configured logger the autowired one is used.');
        }
    }

    public function testNullLoggerServiceIsAvailableToDisableLogging(): void
    {
        $extension = new ItkDevOpenIdConnectExtension();
        $container = new ContainerBuilder();

        $config = $this->getBaseConfig();
        $config['logging_options'] = ['logger' => 'itkdev_openid_connect.null_logger'];

        $extension->load([$config], $container);

        // The README documents this service id as the way to turn logging off,
        // so it must keep existing and be a NullLogger.
        $this->assertTrue($container->hasDefinition('itkdev_openid_connect.null_logger'));
        $this->assertSame(NullLogger::class, $container->getDefinition('itkdev_openid_connect.null_logger')->getClass());

        $arguments = $container->getDefinition(LoginController::class)->getArguments();
        $this->assertEquals(new Reference('itkdev_openid_connect.null_logger'), $arguments['$logger']);
    }

    public function testLoggingOptionsAreWiredToServices(): void
    {
        $extension = new ItkDevOpenIdConnectExtension();
        $container = new ContainerBuilder();

        $config = $this->getBaseConfig();
        $config['logging_options'] = [
            'logger' => 'monolog.logger.openid_connect',
            'level' => LogLevel::CRITICAL,
        ];

        $extension->load([$config], $container);

        foreach ([LoginController::class, CliLoginTokenAuthenticator::class] as $id) {
            $arguments = $container->getDefinition($id)->getArguments();
            $this->assertSame(LogLevel::CRITICAL, $arguments['$logLevel']);
            $this->assertEquals(new Reference('monolog.logger.openid_connect'), $arguments['$logger']);
        }
    }

    public function testAuthenticatorSubclassesGetLoggingViaAutoconfiguration(): void
    {
        $extension = new ItkDevOpenIdConnectExtension();
        $container = new ContainerBuilder();

        $config = $this->getBaseConfig();
        $config['logging_options'] = [
            'logger' => 'monolog.logger.openid_connect',
            'level' => LogLevel::WARNING,
        ];

        $extension->load([$config], $container);

        // Consumer subclasses are services this extension cannot name, so the
        // configuration has to reach them through autoconfiguration.
        $autoconfigured = $container->getAutoconfiguredInstanceof();
        $this->assertArrayHasKey(OpenIdLoginAuthenticator::class, $autoconfigured);

        $calls = $autoconfigured[OpenIdLoginAuthenticator::class]->getMethodCalls();
        $this->assertEquals([
            ['setLogLevel', [LogLevel::WARNING]],
            ['setLogger', [new Reference('monolog.logger.openid_connect')]],
        ], $calls);
    }

    public function testAuthenticatorSubclassesGetLevelOnlyWhenNoLoggerConfigured(): void
    {
        $extension = new ItkDevOpenIdConnectExtension();
        $container = new ContainerBuilder();

        $extension->load([$this->getBaseConfig()], $container);

        $calls = $container->getAutoconfiguredInstanceof()[OpenIdLoginAuthenticator::class]->getMethodCalls();
        $this->assertSame([['setLogLevel', [LogLevel::ERROR]]], $calls);
    }

    public function testLoadWiresProviderManagerConfig(): void
    {
        $extension = new ItkDevOpenIdConnectExtension();
        $container = new ContainerBuilder();

        $extension->load([$this->getBaseConfig()], $container);

        $config = $container->getDefinition(OpenIdConfigurationProviderManager::class)->getArgument('$config');
        $this->assertIsArray($config);

        $defaultOptions = $config['default_providers_options'] ?? null;
        $this->assertIsArray($defaultOptions);
        $cacheItemPool = $defaultOptions['cacheItemPool'] ?? null;
        $this->assertInstanceOf(Reference::class, $cacheItemPool);
        $this->assertSame('cache.app', (string) $cacheItemPool);

        // Provider options must be keyed by provider name with the
        // intermediate 'options' level stripped.
        $providers = $config['providers'] ?? null;
        $this->assertIsArray($providers);
        $this->assertSame(['test_provider'], array_keys($providers));
        $provider = $providers['test_provider'];
        $this->assertIsArray($provider);
        $this->assertArrayNotHasKey('options', $provider);
        $this->assertSame('test_id', $provider['client_id']);
    }

    public function testLoadWiresCacheAndCliLoginRoute(): void
    {
        $extension = new ItkDevOpenIdConnectExtension();
        $container = new ContainerBuilder();

        $extension->load([$this->getBaseConfig()], $container);

        $cache = $container->getDefinition(CliLoginHelper::class)->getArgument('$cache');
        $this->assertInstanceOf(Reference::class, $cache);
        $this->assertSame('cache.app', (string) $cache);

        $this->assertSame('test_route', $container->getDefinition(UserLoginCommand::class)->getArgument('$cliLoginRoute'));
        $this->assertSame('test_route', $container->getDefinition(CliLoginTokenAuthenticator::class)->getArgument('$cliLoginRoute'));
    }

    public function testLoadWithUserProvider(): void
    {
        $extension = new ItkDevOpenIdConnectExtension();
        $container = new ContainerBuilder();

        $extension->load([$this->getBaseConfig('my_custom_user_provider')], $container);

        $definition = $container->getDefinition(UserLoginCommand::class);
        $userProviderArg = $definition->getArgument('$userProvider');
        $this->assertInstanceOf(Reference::class, $userProviderArg);
        $this->assertSame('my_custom_user_provider', (string) $userProviderArg);
    }

    public function testGetAlias(): void
    {
        $extension = new ItkDevOpenIdConnectExtension();
        $this->assertSame('itkdev_openid_connect', $extension->getAlias());
    }
}

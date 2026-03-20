<?php

namespace ItkDev\OpenIdConnectBundle\Tests\DependencyInjection;

use ItkDev\OpenIdConnectBundle\Command\UserLoginCommand;
use ItkDev\OpenIdConnectBundle\DependencyInjection\ItkDevOpenIdConnectExtension;
use ItkDev\OpenIdConnectBundle\Security\CliLoginTokenAuthenticator;
use ItkDev\OpenIdConnectBundle\Security\OpenIdConfigurationProviderManager;
use ItkDev\OpenIdConnectBundle\Util\CliLoginHelper;
use PHPUnit\Framework\TestCase;
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

<?php

namespace ItkDev\OpenIdConnectBundle\DependencyInjection;

use ItkDev\OpenIdConnectBundle\Command\UserLoginCommand;
use ItkDev\OpenIdConnectBundle\Security\CliLoginTokenAuthenticator;
use ItkDev\OpenIdConnectBundle\Security\OpenIdConfigurationProviderManager;
use ItkDev\OpenIdConnectBundle\Util\CliLoginHelper;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

class ItkDevOpenIdConnectExtension extends Extension
{
    /**
     * {@inheritdoc}
     *
     * @throws \Exception
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.yaml');

        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $definition = $container->getDefinition(OpenIdConfigurationProviderManager::class);

        $providersConfig = [
            'default_providers_options' => [
                'cacheItemPool' => new Reference($config['cache_options']['cache_pool']),
            ],
            'providers' => array_map(static fn (array $options) => $options['options'], $config['openid_providers']),
        ];
        $definition->replaceArgument('$config', $providersConfig);

        $definition = $container->getDefinition(CliLoginHelper::class);
        $definition->replaceArgument('$cache', new Reference($config['cache_options']['cache_pool']));

        $definition = $container->getDefinition(UserLoginCommand::class);
        $definition->replaceArgument('$cliLoginRoute', $config['cli_login_options']['route']);
        if (null !== $config['user_provider']) {
            $definition->setArgument('$userProvider', new Reference($config['user_provider']));
        }

        $definition = $container->getDefinition(CliLoginTokenAuthenticator::class);
        $definition->replaceArgument('$cliLoginRoute', $config['cli_login_options']['route']);
        if (null !== $config['user_provider']) {
            $definition->setArgument('$userProvider', new Reference($config['user_provider']));
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getAlias(): string
    {
        return 'itkdev_openid_connect';
    }
}
